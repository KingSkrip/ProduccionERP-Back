<?php

namespace App\Http\Controllers\Scanner;

use App\Events\Scanner\ScanEmbarqueCreado;
use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ScannerEmbarquesController extends Controller
{
    protected FirebirdConnectionService $firebird;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }

    public function index()
    {
        $connection = $this->firebird->getProductionConnection();

        $scans = $connection
            ->table('INVFISVSTEOPT as I')
            ->leftJoin('PSDTABPZAS as PSD', 'PSD.CLAVE', '=', 'I.CODIGOENT')
            ->orderByDesc('I.FECHAYHORA')
            ->limit(200)
            ->get([
                'I.CODIGO',
                'I.CODIGOENT',
                'I.FECHAYHORA',
                'I.PROCESADO',
                'PSD.PNETO as PESO',
            ]);

        return response()->json(['data' => $scans]);
    }

    public function scan(Request $request)
    {
        Log::info('📦 [scan] ▶ INICIO', [
            'ip' => $request->ip(),
            'barcode' => $request->barcode,
            'all_input' => $request->all(),
            'userId' => $request->firebird_user_id,
        ]);

        // ── 1. Extraer userId ──────────────────────────────────────────────────
        $userId = $request->firebird_user_id
            ?? $request->user()?->firebird_user_id
            ?? $request->user()?->ID;

        if (! $userId) {
            Log::error('❌ [scan] userId es null, no se puede broadcast');

            return response()->json(['ok' => false, 'error' => 'Sin userId'], 400);
        }

        Log::info('🎯 [scan] Broadcasting a canal:', [
            'canal' => "scanner-embarques.{$userId}",
            'userId_type' => gettype($userId),
            'userId_value' => $userId,
        ]);

        // ── 2. Validar formato del código ─────────────────────────────────────
        $codigoOriginal = trim($request->barcode);

        if (! preg_match('/^\d{10}$/', $codigoOriginal)) {
            Log::warning('⛔ [scan] Código inválido, se ignora', ['barcode' => $codigoOriginal]);

            return response()->json([
                'ok' => false,
                'motivo' => 'codigo_invalido',
                'codigo' => $codigoOriginal,
            ], 422);
        }

        $codigoCeros = $codigoOriginal;
        $codigoLimpio = ltrim($codigoOriginal, '0') ?: '0'; // Para el cast a int en CODIGOENT
        $fechaYHora = now()->toDateTimeString();

        // ── 3. Conexión Firebird ───────────────────────────────────────────────
        Log::info('🔌 [scan] Obteniendo conexión Firebird...');
        try {
            $connection = $this->firebird->getProductionConnection();
            Log::info('✅ [scan] Conexión Firebird OK');
        } catch (\Throwable $e) {
            Log::error('❌ [scan] Falló la conexión Firebird', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        // ── 4. Verificar duplicado ANTES de insertar ──────────────────────────────

        // Ya procesado (PROCESADO = 1) → suena "ya_inventariado"
        $yaInventariado = $connection->table('INVFISVSTEOPT')
            ->where('CODIGO', $codigoCeros)
            ->where('PROCESADO', 1)
            ->exists();

        if ($yaInventariado) {
            Log::info('⚠️ [scan] Código ya inventariado (PROCESADO=1)', ['codigo' => $codigoCeros]);

            return response()->json([
                'ok' => false,
                'motivo' => 'ya_inventariado',
                'codigo' => $codigoCeros,
            ], 409);
        }

        // Pendiente duplicado (PROCESADO = 0)
        $yaExiste = $connection->table('INVFISVSTEOPT')
            ->where('CODIGO', $codigoCeros)
            ->where('PROCESADO', 0)
            ->exists();

        if ($yaExiste) {
            Log::info('⚠️ [scan] Código ya registrado con PROCESADO=0', ['codigo' => $codigoCeros]);

            return response()->json([
                'ok' => false,
                'motivo' => 'duplicado',
                'codigo' => $codigoCeros,
            ], 409);
        }

        // ── 4. Insert en INVFISVSTEOPT ─────────────────────────────────────────
        $payload = [
            'CODIGO' => $codigoCeros,
            'CODIGOENT' => (int) $codigoLimpio,
            'FECHAYHORA' => $fechaYHora,
            'PROCESADO' => 0,
        ];
        Log::info('💾 [scan] Insertando en INVFISVSTEOPT...', ['payload' => $payload]);

        try {
            $connection->table('INVFISVSTEOPT')->insert($payload);
            Log::info('✅ [scan] Insert OK');
        } catch (\Throwable $e) {
            Log::error('❌ [scan] Falló el insert', [
                'message' => $e->getMessage(),
                'payload' => $payload,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['ok' => false, 'error' => 'Error al guardar el scan'], 500);
        }

        // ── 5. Broadcast del evento ────────────────────────────────────────────
        Log::info('📡 [scan] Disparando broadcast ScanEmbarqueCreado...', [
            'codigo' => $codigoCeros,
            'codigoEnt' => (int) $codigoLimpio,
            'userId' => $userId,
        ]);

        try {
            broadcast(new ScanEmbarqueCreado(
                codigo: $codigoCeros,
                codigoEnt: (int) $codigoLimpio,
                fechaYHora: $fechaYHora,
                procesado: 0,
                userId: $userId,
            ));
            Log::info('✅ [scan] Broadcast OK');
        } catch (\Throwable $e) {
            // No reventamos el request por esto, solo lo logueamos
            Log::warning('⚠️ [scan] Broadcast falló (no crítico)', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // ── 6. Respuesta final ─────────────────────────────────────────────────
        Log::info('🏁 [scan] FIN OK', ['codigo' => $codigoCeros]);

        return response()->json(['ok' => true, 'codigo' => $codigoCeros]);
    }

    // ScannerEmbarquesController.php
    public function registrarOperador(Request $request)
    {
        $userId = $request->input('user_id');
        Cache::put('scanner_operador_activo', $userId, now()->addHours(8));

        return response()->json(['ok' => true, 'operador' => $userId]);
    }

    public function verificarInventario(Request $request)
    {
        $codigoOriginal = trim($request->barcode);

        if (!preg_match('/^\d{10}$/', $codigoOriginal)) {
            return response()->json([
                'ok'     => false,
                'motivo' => 'codigo_invalido',
            ], 422);
        }

        $connection = $this->firebird->getProductionConnection();

        // 0000413013 -> 413013 (CLAVE en PSDTABPZAS y CODIGOENT en INVFISVSTEOPT)
        $clave = (int) (ltrim($codigoOriginal, '0') ?: '0');

        // ── 1. Buscar en PSDTABPZAS: si no existe, no hay nada que inventariar ─
        $datos = $this->datosRolloAcabado($connection, $clave);

        if ($datos === null) {
            return response()->json([
                'ok'     => false,
                'motivo' => 'no_encontrado',
                'codigo' => $codigoOriginal,
            ], 404);
        }

        // ── 2. Revisar en INVFISVSTEOPT por CODIGOENT si ya está registrado ────
        $registro = $connection->table('INVFISVSTEOPT')
            ->where('CODIGOENT', $clave)
            ->orderByDesc('PROCESADO') // si hay varios, que gane el aprobado
            ->first(['CODIGO', 'CODIGOENT', 'PROCESADO']);

        $registro = $registro ? (array) $registro : null;

        // Ya aprobado (PROCESADO = 1)
        if ($registro && (int) $registro['PROCESADO'] === 1) {
            return response()->json([
                'ok'     => false,
                'motivo' => 'ya_inventariado',
                'codigo' => $codigoOriginal,
                'datos'  => $datos,
                'peso'   => (float) ($datos['PESO NETO'] ?? 0),
            ], 409);
        }

        // ── 3. Respuesta (sin broadcast: el front encola con esta respuesta) ───
        return response()->json([
            'ok'           => true,
            'motivo'       => $registro ? 'ya_pendiente' : 'no_inventariado',
            'ya_pendiente' => $registro !== null,
            'codigo'       => $codigoOriginal,
            'codigo_ent'   => $clave,
            'datos'        => $datos,
            'peso'         => (float) ($datos['PESO NETO'] ?? 0),
        ], 200);
    }

    /**
     * Datos del rollo en ACABADO (peso, tipo, artículo, cliente, etc.) para un código.
     * Regresa null si no está en acabado o si falla la consulta.
     */
    private function datosRolloAcabado($connection, int $clave): ?array
    {
        $sql = "
        SELECT
            PSD.CLAVE AS ID,
            LPAD(PSD.CLAVE,10,'0') AS ID_QR,
            P.CLAVE AS \"CVE ART\",
            P.ARTICULO AS ARTICULO,
            P.CLIENTE AS CLIENTE,
            COALESCE(V.agente,'SIN AGENTE') AS AGENTE,
            P.PEDIDO AS PEDIDO,
            P.PARTIDA AS OP,
            OE.PEDIDOPART AS PEDIDOPART,
            P.\"COD. COLOR\" AS \"COD. COLOR\",
            P.COLOR AS COLOR,
            P.FECHA AS FECHA,
            PSD.TIPO AS TIPO_COD,
            CASE PSD.TIPO
                WHEN 51 THEN 'PRIMERA'
                WHEN 52 THEN 'PREFERIDA'
                WHEN 73 THEN 'ORILLAS'
                WHEN 74 THEN 'RETAZO'
                WHEN 77 THEN 'SEGUNDA'
                WHEN 81 THEN 'MUESTRA'
                ELSE 'OTRAS'
            END AS TIPO,
            PSD.PNETO AS \"PESO NETO\",
            PSD.PIEZA AS PIEZA,
            PSD.ESTATUS AS PSD_ESTATUS,
            PSD.ISDELIV AS ISDELIV,
            PSD.FECHAYHORAINGPT AS \"FECHA ING\",
            PSD.FECHAYHORASALPT AS \"FECHA SAL\",
            PSD.FECHAYHORADEVOL AS \"FECHA DEV\",
            PSD.ID_FOL_PL AS PL,
            OE.ORDEN AS ORDEN,
            OE.ESTATUS AS OE_ESTATUS,
            IIF(OE.ESTATUS = 2, S.PROCESO, E.ESTATUS) AS PROCESO,
            IIF(OE.ESTATUS = 2, D.DEPTO, NULL) AS ALMACEN,
            IIF(
                PSD.FECHAYHORADEVOL IS NOT NULL,
                '',
                IIF(OE.ESTATUS IN (4, 50, 51, 61, 65), 'ROLLO', 'TELA')
            ) AS PRODUCTO,
            OE.CANTIDAD AS \"CANTIDAD SOLICITADA\",
            OE.CANTENT AS \"CANTIDAD ENTREGADA\"
        FROM PSDTABPZAS PSD
        INNER JOIN P_PSDENC('03') P ON P.CVE_PSD_ENC = PSD.CVE_ENC
        LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
        LEFT JOIN p_vendxx('03') V ON V.id = OE.agente
        LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
        LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
        LEFT JOIN DEPTOS D ON D.CLAVE = S.DEPTO
        LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
        WHERE PSD.CLAVE = ?
    ";

        try {
            $row = $connection->selectOne($sql, [$clave]);

            return $row ? (array) $row : null;
        } catch (\Throwable $e) {
            Log::warning('⚠️ [datosRolloAcabado] Falló la consulta', [
                'clave' => $clave,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}