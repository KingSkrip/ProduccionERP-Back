<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ConrrecionAcabadoController extends Controller
{
    protected FirebirdConnectionService $firebird;

    private const ESTATUS_EDITABLE = 3;

    private const MAX_LOTE = 300;      // piezas/ids por petición

    private const MAX_OP_COMPLETA = 400; // tope al traer toda una OP

    private const MAX_ELIMINAR_LOTE = 300;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }

    /* =======================================================
        🧪 HELPERS DE DEBUG (solo logs, no alteran lógica)
    ======================================================= */

    /** Log de depuración con usuario e IP automáticos. */
    private function dbg(string $tag, array $ctx = [], string $level = 'info'): void
    {
        $base = [
            'user_id' => auth()->user()?->ID,
            'ip' => request()->ip(),
        ];

        Log::{$level}("[correccion-acabado] {$tag}", array_merge($base, $ctx));
    }

    /** Log de error completo (clase, archivo, línea, trace). */
    private function dbgError(string $tag, \Throwable $e, array $ctx = []): void
    {
        Log::error("[correccion-acabado] {$tag}", array_merge([
            'user_id' => auth()->user()?->ID,
            'ip' => request()->ip(),
            'message' => $e->getMessage(),
            'class' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ], $ctx));
    }

    /**
     * Un solo escaneo: { rollo: "0000413013" }
     * Regresa OP, PIEZA, ID del rollo y demás datos.
     */
    public function scan(Request $request)
    {
        $inicio = microtime(true);

        $rollo = trim((string) $request->input('rollo', ''));
        $op = trim((string) $request->input('op', ''));
        $pieza = trim((string) $request->input('pieza', ''));

        $this->dbg('▶️ SCAN inicio', ['rollo' => $rollo, 'op' => $op, 'pieza' => $pieza]);

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($rollo !== '') {
                if (! preg_match('/^\d{1,10}$/', $rollo)) {
                    $this->dbg('⚠️ SCAN rollo inválido', ['rollo' => $rollo], 'warning');

                    return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $rollo], 422);
                }
                $clave = (int) (ltrim($rollo, '0') ?: '0');
            } else {
                if ($op === '' || strlen($op) > 30) {
                    $this->dbg('⚠️ SCAN op inválida', ['op' => $op], 'warning');

                    return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
                }
                if (! preg_match('/^\d{1,10}$/', $pieza)) {
                    $this->dbg('⚠️ SCAN pieza inválida', ['pieza' => $pieza], 'warning');

                    return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $pieza], 422);
                }

                $clave = $this->claveRolloPorOpPieza($conn, $op, (int) $pieza);

                $this->dbg('🔎 SCAN clave por OP+pieza', ['op' => $op, 'pieza' => $pieza, 'clave' => $clave]);

                if ($clave === null) {
                    $this->dbg('⚠️ SCAN pieza no encontrada', ['op' => $op, 'pieza' => $pieza], 'warning');

                    return response()->json(['ok' => false, 'motivo' => 'pieza_no_encontrada'], 404);
                }
            }

            $datos = $this->datosRolloAcabado($conn, $clave);
        } catch (\Throwable $e) {
            $this->dbgError('scan', $e, ['rollo' => $rollo, 'op' => $op, 'pieza' => $pieza]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        if ($datos === null) {
            $this->dbg('⚠️ SCAN rollo no encontrado', ['clave' => $clave], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

        $this->dbg('✅ SCAN fin', [
            'clave' => $clave,
            'pieza' => $datos['PIEZA'] ?? null,
            'oe_estatus' => $datos['OE_ESTATUS'] ?? null,
            'psd_estatus' => $datos['PSD_ESTATUS'] ?? null,
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);

        return response()->json([
            'ok' => true,
            'rollo' => str_pad((string) $clave, 10, '0', STR_PAD_LEFT), // siempre la CLAVE real
            'pieza' => $datos['PIEZA'],
            'op' => trim((string) $datos['OP']),
            'peso' => (float) $datos['PESO NETO'],
            'datos' => $datos,
        ]);
    }

    /** Busca la CLAVE del rollo a partir de OP (partida u orden) + número de pieza. */
    private function claveRolloPorOpPieza($connection, string $op, int $pieza): ?int
    {
        $r = $connection->selectOne("
        SELECT FIRST 1 PSD.CLAVE
        FROM P_PSDENC('".config('firebird.company')."') P
        LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
        INNER JOIN PSDTABPZAS PSD ON PSD.CVE_ENC = P.CVE_PSD_ENC
        WHERE (P.PARTIDA = ? OR OE.ORDEN = ?)
          AND PSD.PIEZA = ?
        ORDER BY COALESCE(PSD.ESTATUS, 1) DESC, PSD.CLAVE DESC
    ", [$op, $op, $pieza]);

        return $r ? (int) ((array) $r)['CLAVE'] : null;
    }

    private function datosRolloAcabado($connection, int $clave): ?array
    {
        try {
            // 1) Rollo directo por CLAVE (rápido)
            $row = $connection->selectOne("
        SELECT
            PSD.CLAVE AS ID,
            PSD.CVE_ENC AS CVE_ENC,
            PSD.ESTATUS AS PSD_ESTATUS,
            PSD.TIPO AS TIPO_COD,
            CASE PSD.TIPO
                WHEN 51 THEN 'PRIMERA' WHEN 52 THEN 'PREFERIDA' WHEN 73 THEN 'ORILLAS'
                WHEN 74 THEN 'RETAZO'  WHEN 77 THEN 'SEGUNDA'   WHEN 81 THEN 'MUESTRAS'
                ELSE 'OTRAS'
            END AS TIPO,
            PSD.PNETO AS \"PESO NETO\",
            PSD.PIEZA AS PIEZA,
            PSD.PBRUT AS PESO_BRUTO,
            PSD.TARA AS TARA,
            PSD.LONGITUD AS LONGITUD,
            CASE PSD.TONO
                WHEN 0 THEN 'TONO A' WHEN 1 THEN 'TONO B' WHEN 2 THEN 'TONO C'
                ELSE 'TONO ' || PSD.TONO
            END AS TONO,
            PSD.FECHAYHORA AS FECHA,
            PSD.MUESTRADELROLLO AS MUESTRA,
            COALESCE(PSD.IS_PL, 0) AS PL
        FROM PSDTABPZAS PSD
        WHERE PSD.CLAVE = ?
    ", [$clave]);

            if (! $row) {
                $this->dbg('⚠️ datosRolloAcabado: rollo no existe en PSDTABPZAS', ['clave' => $clave], 'warning');

                return null;
            }

            $row = (array) $row;

            // 2) Encabezado de la partida (cacheado)
            $enc = $this->encabezado($connection, $row['CVE_ENC']);

            if (! $enc) {
                $this->dbg('⚠️ datosRolloAcabado: sin encabezado', [
                    'clave' => $clave,
                    'cve_enc' => $row['CVE_ENC'] ?? null,
                ], 'warning');

                return null;
            }

            $row = array_merge($row, $enc);

            // 3) Estatus de la orden, SIEMPRE fresco (sin caché)
            $row['OE_ESTATUS'] = $this->estatusOrden($connection, $enc['OE_ID'] ?? null);

            // Campos internos que no se mandan al front
            unset($row['CVE_ENC'], $row['OE_ID']);

            if (! empty($row['FECHA'])) {
                $row['FECHA'] = str_replace(' ', 'T', substr((string) $row['FECHA'], 0, 19));
            }

            return $row;
        } catch (\Throwable $e) {
            Log::warning('⚠️ [correccion-acabado] Falló la consulta del rollo', [
                'clave' => $clave,
                'message' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    public function articulo(Request $request)
    {
        $inicio = microtime(true);
        $op = trim((string) $request->query('op'));

        $this->dbg('▶️ ARTICULO inicio', ['op' => $op]);

        if ($op === '') {
            $this->dbg('⚠️ ARTICULO op inválida', [], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
        }

        try {
            $row = Cache::remember("ca:articulo:$op", 3600, function () use ($op) {
                // Este closure solo corre en cache MISS
                $this->dbg('💾 ARTICULO cache MISS, consultando Firebird', ['op' => $op]);

                $r = $this->firebird->getProductionConnection()->selectOne(
                    "SELECT FIRST 1 V.ID, V.ARTICULO, V.ANCHO, V.PESO, V.REND, V.ANCHO2, V.PESO2, V.REND2
                   FROM ORDENESENC OE
                  INNER JOIN V_ARTICULOS2NOAUT V ON V.ID = OE.CVE_ART
                  WHERE OE.ORDEN = ? AND V.TIPON = 0 AND V.NOMBRE <> ''",
                    [$op]
                );

                return $r ? (array) $r : null;
            });
        } catch (\Throwable $e) {
            $this->dbgError('articulo', $e, ['op' => $op]);

            return response()->json(['ok' => false, 'error' => 'No se pudo consultar el artículo'], 500);
        }

        if (! $row) {
            $this->dbg('⚠️ ARTICULO no encontrado', ['op' => $op], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'articulo_no_encontrado'], 404);
        }

        $this->dbg('✅ ARTICULO fin', [
            'op' => $op,
            'articulo' => trim((string) $row['ARTICULO']),
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);

        return response()->json([
            'ok' => true,
            'articulo' => trim((string) $row['ARTICULO']),
            'ancho' => (float) $row['ANCHO'],
            'ancho_yds' => (float) $row['ANCHO2'],
            'gramaje' => (float) $row['PESO'],
            'gramaje_oz' => (float) $row['PESO2'],
            'rendimiento' => (float) $row['REND'],
            'rendimiento_yd_lb' => (float) $row['REND2'],
        ]);
    }

    public function update(Request $request)
    {
        $inicio = microtime(true);

        $this->dbg('▶️ UPDATE inicio (payload crudo)', ['payload' => $request->all()]);

        $v = $request->validate([
            'rollo' => ['required', 'regex:/^\d{1,10}$/'],
            'kilogramos' => ['required', 'numeric', 'gt:0'],
            'libras' => ['required', 'numeric', 'gt:0'],
            'metros' => ['required', 'numeric', 'gt:0'],
            'yardas' => ['required', 'numeric', 'gt:0'],
            'tara' => ['required', 'numeric', 'min:0'],
            'pbruto' => ['required', 'numeric', 'gte:kilogramos'],
            'tipo' => ['nullable', 'integer', Rule::in([51, 52, 73, 74, 77, 81])],
        ]);

        $clave = (int) (ltrim($v['rollo'], '0') ?: '0');
        $pneto = round((float) $v['kilogramos'], 3);
        $tara = round((float) $v['tara'], 3);
        $pbrut = round((float) $v['pbruto'], 3);
        $metros = round((float) $v['metros'], 3);

        $this->dbg('🧮 UPDATE valores calculados', [
            'clave' => $clave,
            'pneto' => $pneto,
            'tara' => $tara,
            'pbrut' => $pbrut,
            'metros' => $metros,
            'diff_pesos' => round(abs(($pneto + $tara) - $pbrut), 4),
        ]);

        if (abs(($pneto + $tara) - $pbrut) > 0.01) {
            $this->dbg('⚠️ UPDATE pesos inconsistentes', [
                'clave' => $clave,
                'pneto' => $pneto,
                'tara' => $tara,
                'pbrut' => $pbrut,
            ], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'pesos_inconsistentes'], 422);
        }

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                $this->dbg('⛔ UPDATE bloqueado por validarEstatus', [
                    'clave' => $clave,
                    'http' => $bloqueo->getStatusCode(),
                    'respuesta' => $bloqueo->getData(true),
                ], 'warning');

                return $bloqueo;
            }

            $sets = 'PESOKG = ?, PNETO = ?, PESOLB = ?, MTS = ?, LONGITUD = ?, YDS = ?, TARA = ?, PBRUT = ?';
            $params = [$pneto, $pneto, round((float) $v['libras'], 3), $metros, $metros,
                round((float) $v['yardas'], 3), $tara, $pbrut];

            if (isset($v['tipo'])) {
                $sets .= ', TIPO = ?';
                $params[] = (int) $v['tipo'];
            }
            $params[] = $clave;

            $sql = "UPDATE PSDTABPZAS SET $sets WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0";

            $this->dbg('🧪 UPDATE SQL', [
                'sql' => $sql,
                'placeholders' => substr_count($sql, '?'),
                'params_count' => count($params),
                'params' => $params,
            ]);

            $afectados = $conn->update($sql, $params);

            $this->dbg('📦 UPDATE filas afectadas', ['clave' => $clave, 'afectados' => $afectados]);
        } catch (\Throwable $e) {
            $this->dbgError('UPDATE', $e, ['clave' => $clave]);

            return response()->json(['ok' => false, 'error' => 'No se pudo guardar'], 500);
        }

        if ($afectados < 1) {
            $this->dbg('⚠️ UPDATE 0 filas (rollo eliminado)', ['clave' => $clave], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'rollo_eliminado', 'error' => 'El rollo ya fue eliminado'], 409);
        }

        $this->dbg('✅ UPDATE fin', ['clave' => $clave, 'ms' => round((microtime(true) - $inicio) * 1000, 2)]);

        return response()->json(['ok' => true]);
    }

    public function eliminar(Request $request)
    {
        $inicio = microtime(true);

        $this->dbg('▶️ ELIMINAR inicio', ['payload' => $request->all()]);

        $v = $request->validate([
            'rollo' => ['required', 'regex:/^\d{1,10}$/'],
        ]);

        $clave = (int) (ltrim($v['rollo'], '0') ?: '0');

        [$usdel, $namedel] = $this->quienElimina();

        $usuario = auth()->user();
        $userId = (int) $usuario->ID;

        $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first()
            ?? UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();

        $tbClave = $identity?->firebird_tb_clave;
        $tbClave = is_string($tbClave) ? trim($tbClave) : $tbClave;

        $this->dbg('🪪 ELIMINAR identidad', [
            'clave_rollo' => $clave,
            'usuario_ID' => $userId,
            'usuario_CLAVE' => $usuario->CLAVE ?? null,
            'identity_encontrada' => (bool) $identity,
            'identity_id' => $identity->id ?? null,
            'identity_firebird_user_clave' => $identity->firebird_user_clave ?? null,
            'tbClave' => $tbClave,
        ]);

        if (! $tbClave) {
            $this->dbg('⛔ ELIMINAR rechazado: usuario sin clave TB', [
                'usuario_ID' => $userId,
                'identity_encontrada' => (bool) $identity,
            ], 'warning');

            return response()->json(['ok' => false, 'error' => 'Usuario sin clave de empleado (TB)'], 403);
        }

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                $this->dbg('⛔ ELIMINAR bloqueado por validarEstatus', [
                    'clave' => $clave,
                    'http' => $bloqueo->getStatusCode(),
                    'respuesta' => $bloqueo->getData(true),
                ], 'warning');

                return $bloqueo;
            }


            $sql = 'UPDATE PSDTABPZAS
            SET ESTATUS = 0,
                FECHAYHORADEL = CURRENT_TIMESTAMP,
                USDEL = ?,
                NAMEDEL = ?,
                CASEDEL = 1
          WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0';
    
        $this->dbg('🧪 ELIMINAR SQL', [
            'placeholders' => substr_count($sql, '?'),
            'params' => [$usdel, $namedel, $clave],
        ]);
    
        $afectados = $conn->update($sql, [$usdel, $namedel, $clave]);

            $this->dbg('📦 ELIMINAR filas afectadas', ['clave' => $clave, 'afectados' => $afectados]);
        } catch (\Throwable $e) {
            $this->dbgError('ELIMINAR', $e, ['clave' => $clave, 'tb' => $tbClave]);

            return response()->json(['ok' => false, 'error' => 'No se pudo eliminar'], 500);
        }

        if ($afectados < 1) {
            $this->dbg('⚠️ ELIMINAR 0 filas (ya eliminado)', ['clave' => $clave], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'rollo_eliminado', 'error' => 'El rollo ya fue eliminado'], 409);
        }

        $this->dbg('✅ ELIMINAR fin', [
            'clave' => $clave,
            'tb' => $tbClave,
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);

        return response()->json(['ok' => true]);
    }

    private function encabezado($connection, $cveEnc): ?array
    {
        return Cache::remember("ca:enc2:$cveEnc", 21600, function () use ($connection, $cveEnc) {
            // Este closure solo corre en cache MISS
            $this->dbg('💾 ENCABEZADO cache MISS, consultando Firebird', ['cve_enc' => $cveEnc]);

            $e = $connection->selectOne("
            SELECT P.ARTICULO AS ARTICULO, P.PARTIDA AS OP, OE.ORDEN AS ORDEN, OE.ID AS OE_ID
            FROM P_PSDENC('".config('firebird.company')."') P
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            WHERE P.CVE_PSD_ENC = ?
        ", [$cveEnc]);

            if (! $e) {
                $this->dbg('⚠️ ENCABEZADO no encontrado (se cacheará null)', ['cve_enc' => $cveEnc], 'warning');
            }

            return $e ? (array) $e : null;
        });
    }

    private function estatusOrden($connection, $oeId): ?int
    {
        if (! $oeId) {
            $this->dbg('⚠️ estatusOrden sin OE_ID', [], 'warning');

            return null;
        }
        $r = $connection->selectOne('SELECT ESTATUS FROM ORDENESENC WHERE ID = ?', [$oeId]);

        return $r ? (int) ((array) $r)['ESTATUS'] : null;
    }

    private function validarEstatus($connection, int $clave): ?JsonResponse
    {
        $psd = $connection->selectOne(
            'SELECT CVE_ENC, ESTATUS FROM PSDTABPZAS WHERE CLAVE = ?',
            [$clave]
        );
        if (! $psd) {
            $this->dbg('⚠️ validarEstatus: rollo no encontrado', ['clave' => $clave], 'warning');

            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

        $psd = (array) $psd;

        // NUEVO: rollo ya eliminado
        if ($psd['ESTATUS'] !== null && (int) $psd['ESTATUS'] === 0) {
            $this->dbg('⚠️ validarEstatus: rollo ya eliminado', ['clave' => $clave, 'psd' => $psd], 'warning');

            return response()->json([
                'ok' => false,
                'motivo' => 'rollo_eliminado',
                'error' => 'El rollo ya fue eliminado',
            ], 409);
        }

        $enc = $this->encabezado($connection, $psd['CVE_ENC']);
        $estatus = $this->estatusOrden($connection, $enc['OE_ID'] ?? null);

        $this->dbg('🔍 validarEstatus', [
            'clave' => $clave,
            'psd_estatus' => $psd['ESTATUS'],
            'cve_enc' => $psd['CVE_ENC'] ?? null,
            'oe_id' => $enc['OE_ID'] ?? null,
            'oe_estatus' => $estatus,
            'estatus_requerido' => self::ESTATUS_EDITABLE,
        ]);

        if ($estatus !== self::ESTATUS_EDITABLE) {
            return response()->json([
                'ok' => false,
                'motivo' => 'estatus_no_permitido',
                'estatus' => $estatus,
                'error' => 'La orden tiene estatus '.($estatus ?? 'desconocido').' y no se puede modificar (debe ser 3)',
            ], 409);
        }

        return null;
    }

    /** Varias piezas de una OP, toda la OP, o varios IDs. */
    public function lote(Request $request)
    {
        $inicio = microtime(true);

        $this->dbg('▶️ LOTE inicio', [
            'op' => $request->input('op'),
            'piezas' => is_array($request->input('piezas')) ? count($request->input('piezas')) : 0,
            'claves' => is_array($request->input('claves')) ? count($request->input('claves')) : 0,
        ]);

        $v = $request->validate([
            'op' => ['nullable', 'string', 'max:30'],
            'piezas' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'piezas.*' => ['integer', 'min:0'],
            'claves' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'claves.*' => ['regex:/^\d{1,10}$/'],
        ]);

        $op = trim((string) ($v['op'] ?? ''));
        $piezas = array_values(array_unique(array_map('intval', $v['piezas'] ?? [])));
        $faltan = [];

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($op !== '') {
                $porPieza = $this->clavesPorOp($conn, $op, $piezas);

                $this->dbg('🔎 LOTE claves por OP', ['op' => $op, 'encontradas' => count($porPieza)]);

                if (! $porPieza) {
                    $this->dbg('⚠️ LOTE pieza no encontrada', ['op' => $op], 'warning');

                    return response()->json(['ok' => false, 'motivo' => 'pieza_no_encontrada'], 404);
                }
                $claves = array_values($porPieza);
                if ($piezas) {
                    $faltan = array_values(array_diff($piezas, array_keys($porPieza)));
                }
            } elseif (! empty($v['claves'])) {
                $claves = array_values(array_unique(array_map(
                    fn ($c) => (int) (ltrim((string) $c, '0') ?: '0'),
                    $v['claves']
                )));
            } else {
                $this->dbg('⚠️ LOTE op inválida (sin op ni claves)', [], 'warning');

                return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
            }

            $rollos = [];
            foreach ($claves as $clave) {
                $datos = $this->datosRolloAcabado($conn, $clave);
                if ($datos === null) {
                    $this->dbg('⚠️ LOTE rollo sin datos', ['clave' => $clave], 'warning');
                    $faltan[] = $op !== '' ? $clave : str_pad((string) $clave, 10, '0', STR_PAD_LEFT);

                    continue;
                }
                $rollos[] = [
                    'ok' => true,
                    'rollo' => str_pad((string) $clave, 10, '0', STR_PAD_LEFT),
                    'pieza' => $datos['PIEZA'],
                    'op' => trim((string) $datos['OP']),
                    'peso' => (float) $datos['PESO NETO'],
                    'datos' => $datos,
                ];
            }
        } catch (\Throwable $e) {
            $this->dbgError('lote', $e, ['op' => $op]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        $this->dbg('✅ LOTE fin', [
            'rollos' => count($rollos),
            'no_encontrados' => count($faltan),
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);

        return response()->json([
            'ok' => true,
            'rollos' => $rollos,
            'no_encontrados' => $faltan,
        ]);
    }

    public function scanLote(Request $request)
    {
        $inicio = microtime(true);

        $this->dbg('▶️ SCAN_LOTE inicio', [
            'op' => $request->input('op'),
            'piezas' => is_array($request->input('piezas')) ? count($request->input('piezas')) : 0,
            'claves' => is_array($request->input('claves')) ? count($request->input('claves')) : 0,
        ]);

        $v = $request->validate([
            'op' => ['nullable', 'string', 'max:30', 'required_without:claves'],
            'piezas' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'piezas.*' => ['integer', 'min:0', 'max:9999999999'],
            'claves' => ['nullable', 'array', 'max:'.self::MAX_LOTE],
            'claves.*' => ['integer', 'min:0', 'max:9999999999'],
        ]);

        $op = trim((string) ($v['op'] ?? ''));
        $piezas = array_values(array_unique(array_map('intval', $v['piezas'] ?? [])));
        $claves = array_values(array_unique(array_map('intval', $v['claves'] ?? [])));

        $items = [];
        $noEncontradas = [];
        $truncado = false;

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($claves) {
                // modo IDs: [clave => clave]
                $mapa = array_combine($claves, $claves);
                $this->dbg('🔑 SCAN_LOTE modo IDs', ['claves' => count($claves)]);
            } else {
                $mapa = $this->clavesPorOp($conn, $op, $piezas);   // [pieza => clave]
                $noEncontradas = $piezas ? array_values(array_diff($piezas, array_keys($mapa))) : [];

                $this->dbg('🔑 SCAN_LOTE modo OP', [
                    'op' => $op,
                    'piezas_pedidas' => count($piezas),
                    'encontradas' => count($mapa),
                    'no_encontradas' => count($noEncontradas),
                ]);

                if (! $piezas && ! $mapa) {
                    $this->dbg('⚠️ SCAN_LOTE OP sin rollos', ['op' => $op], 'warning');

                    return response()->json(['ok' => false, 'motivo' => 'op_sin_rollos'], 404);
                }
                if (count($mapa) > self::MAX_OP_COMPLETA) {
                    $this->dbg('✂️ SCAN_LOTE truncado', [
                        'total' => count($mapa),
                        'max' => self::MAX_OP_COMPLETA,
                    ], 'warning');

                    $mapa = array_slice($mapa, 0, self::MAX_OP_COMPLETA, true);
                    $truncado = true;
                }
            }

            foreach ($mapa as $llave => $clave) {
                $datos = $this->datosRolloAcabado($conn, $clave);
                if ($datos === null) {
                    $this->dbg('⚠️ SCAN_LOTE rollo sin datos', ['llave' => $llave, 'clave' => $clave], 'warning');
                    $noEncontradas[] = $llave;

                    continue;
                }
                $items[] = $this->formatoRollo($clave, $datos);
            }
        } catch (\Throwable $e) {
            $this->dbgError('scanLote', $e, ['op' => $op]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        $this->dbg('✅ SCAN_LOTE fin', [
            'total' => count($items),
            'no_encontradas' => count($noEncontradas),
            'truncado' => $truncado,
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);

        return response()->json([
            'ok' => true,
            'total' => count($items),
            'items' => $items,
            'no_encontradas' => $noEncontradas,
            'truncado' => $truncado,
        ]);
    }

    /** [pieza => clave]. Con piezas filtra por ellas; sin piezas trae toda la OP (sin eliminados). */
    private function clavesPorOp($connection, string $op, array $piezas): array
    {
        $params = [$op, $op];
        if ($piezas) {
            $filtro = ' AND PSD.PIEZA IN ('.implode(',', array_fill(0, count($piezas), '?')).')';
            array_push($params, ...$piezas);
        } else {
            $filtro = ' AND COALESCE(PSD.ESTATUS, 1) <> 0';
        }

        $sql = "
        SELECT PSD.CLAVE, PSD.PIEZA
        FROM P_PSDENC('".config('firebird.company')."') P
        LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
        INNER JOIN PSDTABPZAS PSD ON PSD.CVE_ENC = P.CVE_PSD_ENC
        WHERE (P.PARTIDA = ? OR OE.ORDEN = ?) $filtro
        ORDER BY PSD.PIEZA, COALESCE(PSD.ESTATUS, 1) DESC, PSD.CLAVE DESC
    ";

        $placeholders = substr_count($sql, '?');
        if ($placeholders !== count($params)) {
            $this->dbg('💥 clavesPorOp DESAJUSTE DE BINDINGS', [
                'placeholders' => $placeholders,
                'params_count' => count($params),
            ], 'error');
        }

        $rows = $connection->select($sql, $params);

        $mapa = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $p = (int) $r['PIEZA'];
            $mapa[$p] ??= (int) $r['CLAVE']; // si hay pieza duplicada, gana la activa
        }
        ksort($mapa);

        $this->dbg('📦 clavesPorOp', [
            'op' => $op,
            'piezas_filtro' => count($piezas),
            'filas' => count($rows),
            'piezas_unicas' => count($mapa),
        ]);

        return $mapa;
    }

    private function formatoRollo(int $clave, array $datos): array
    {
        return [
            'ok' => true,
            'rollo' => str_pad((string) $clave, 10, '0', STR_PAD_LEFT),
            'pieza' => $datos['PIEZA'],
            'op' => trim((string) $datos['OP']),
            'peso' => (float) $datos['PESO NETO'],
            'datos' => $datos,
        ];
    }

    /** Clave de empleado (TB) del usuario logueado, o null. */
    private function tbClaveUsuario(): ?string
    {
        $usuario = auth()->user();
        $userId = (int) $usuario->ID;

        $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first()
            ?? UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();

        $tb = $identity?->firebird_tb_clave;
        $tb = is_string($tb) ? trim($tb) : $tb;

        $this->dbg('🪪 tbClaveUsuario', [
            'usuario_ID' => $userId,
            'usuario_CLAVE' => $usuario->CLAVE ?? null,
            'identity_encontrada' => (bool) $identity,
            'identity_id' => $identity->id ?? null,
            'tb' => $tb ?: null,
        ]);

        return $tb ?: null;
    }


    /**
 * Datos de quien elimina:
 *  - usdel:   clave TB si existe (como ahorita); si no, el ID de USUARIOS
 *  - namedel: NOMBRE de la tabla usuarios
 */
private function quienElimina(): array
{
    $usuario = auth()->user();
    $tb = $this->tbClaveUsuario();

    $usdel = $tb ?: (string) $usuario->ID;
    $nombre = trim((string) ($usuario->NOMBRE ?? ''));
    $namedel = mb_substr($nombre, 0, 60); // ajusta 60 al largo real de NAMEDEL

    $this->dbg('🪪 quienElimina', [
        'usuario_ID' => $usuario->ID,
        'tb' => $tb,
        'usdel' => $usdel,
        'namedel' => $namedel,
        'fallback_a_usuario_id' => ! $tb,
    ]);

    return [$usdel, $namedel];
}


    /** Elimina (baja lógica) varios rollos de golpe. Responde por rollo. */
    public function eliminarLote(Request $request)
    {
        $inicio = microtime(true);
        [$usdel, $namedel] = $this->quienElimina();

        $this->dbg('▶️ ELIMINAR_LOTE inicio', [
            'rollos' => is_array($request->input('rollos')) ? count($request->input('rollos')) : 0,
        ]);

        $v = $request->validate([
            'rollos' => ['required', 'array', 'min:1', 'max:'.self::MAX_ELIMINAR_LOTE],
            'rollos.*' => ['regex:/^\d{1,10}$/'],
        ]);

        $tbClave = $this->tbClaveUsuario();
        if (! $tbClave) {
            $this->dbg('⛔ ELIMINAR_LOTE rechazado: usuario sin clave TB', [], 'warning');

            return response()->json(['ok' => false, 'error' => 'Usuario sin clave de empleado (TB)'], 403);
        }

        $eliminados = [];
        $fallidos = [];

        try {
            $conn = $this->firebird->getProductionConnection();

            foreach (array_unique($v['rollos']) as $rollo) {
                $clave = (int) (ltrim((string) $rollo, '0') ?: '0');
                $id = str_pad((string) $clave, 10, '0', STR_PAD_LEFT);

                try {
                    // mismas reglas que eliminar(): existe, no eliminado, orden en estatus 3
                    if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                        $d = $bloqueo->getData(true);
                        $this->dbg('⛔ ELIMINAR_LOTE item bloqueado', ['rollo' => $id, 'respuesta' => $d], 'warning');

                        $fallidos[] = [
                            'rollo' => $id,
                            'motivo' => $d['motivo'] ?? null,
                            'estatus' => $d['estatus'] ?? null,
                            'error' => $d['error'] ?? null,
                        ];

                        continue;
                    }

                    $afectados = $conn->update(
                        'UPDATE PSDTABPZAS
                            SET ESTATUS = 0,
                                FECHAYHORADEL = CURRENT_TIMESTAMP,
                                USDEL = ?,
                                NAMEDEL = ?,
                                CASEDEL = 1
                          WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0',
                        [$usdel, $namedel, $clave]
                    );

                    $this->dbg('📦 ELIMINAR_LOTE item', ['rollo' => $id, 'afectados' => $afectados]);

                    if ($afectados < 1) {
                        $fallidos[] = ['rollo' => $id, 'motivo' => 'rollo_eliminado'];
                    } else {
                        $eliminados[] = $id;
                    }
                } catch (\Throwable $e) {
                    $this->dbgError('ELIMINAR LOTE item', $e, ['clave' => $clave, 'tb' => $tbClave]);
                    $fallidos[] = ['rollo' => $id, 'error' => 'No se pudo eliminar'];
                }
            }
        } catch (\Throwable $e) {
            $this->dbgError('ELIMINAR LOTE', $e, ['tb' => $tbClave]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        Log::info('[correccion-acabado] ELIMINAR LOTE', [
            'tb' => $tbClave, 'eliminados' => count($eliminados), 'fallidos' => count($fallidos),
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);

        return response()->json([
            'ok' => true,
            'eliminados' => $eliminados,
            'fallidos' => $fallidos,
        ]);
    }
}