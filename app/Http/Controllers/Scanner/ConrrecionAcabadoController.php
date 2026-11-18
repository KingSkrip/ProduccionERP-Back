<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ConrrecionAcabadoController extends Controller
{
    protected FirebirdConnectionService $firebird;

    private const ESTATUS_EDITABLE = 3;

    public function __construct(FirebirdConnectionService $firebird)
    {
        $this->firebird = $firebird;
    }

    /**
     * Un solo escaneo: { rollo: "0000413013" }
     * Regresa OP, PIEZA, ID del rollo y demás datos.
     */
    public function scan(Request $request)
    {
        $rollo = trim((string) $request->input('rollo', ''));
        $op = trim((string) $request->input('op', ''));
        $pieza = trim((string) $request->input('pieza', ''));

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($rollo !== '') {
                // Escáner: por CLAVE
                if (! preg_match('/^\d{1,10}$/', $rollo)) {
                    return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $rollo], 422);
                }
                $clave = (int) (ltrim($rollo, '0') ?: '0');
            } else {
                // Manual: por OP + PIEZA
                if ($op === '' || strlen($op) > 30) {
                    return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
                }
                if (! preg_match('/^\d{1,10}$/', $pieza)) {
                    return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $pieza], 422);
                }

                $clave = $this->claveRolloPorOpPieza($conn, $op, (int) $pieza);

                if ($clave === null) {
                    return response()->json(['ok' => false, 'motivo' => 'pieza_no_encontrada'], 404);
                }
            }

            $datos = $this->datosRolloAcabado($conn, $clave);
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] scan', ['m' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        if ($datos === null) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

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
                return null;
            }

            $row = (array) $row;

            // 2) Encabezado de la partida (cacheado)
            $enc = $this->encabezado($connection, $row['CVE_ENC']);

            if (! $enc) {
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
            ]);

            return null;
        }
    }

    public function articulo(Request $request)
    {
        $op = trim((string) $request->query('op'));
        if ($op === '') {
            return response()->json(['ok' => false, 'motivo' => 'op_invalida'], 422);
        }

        try {
            $row = Cache::remember("ca:articulo:$op", 3600, function () use ($op) {
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
            Log::warning('[correccion-acabado] articulo', ['op' => $op, 'm' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'No se pudo consultar el artículo'], 500);
        }

        if (! $row) {
            return response()->json(['ok' => false, 'motivo' => 'articulo_no_encontrado'], 404);
        }

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
        $v = $request->validate([
            'rollo' => ['required', 'regex:/^\d{1,10}$/'],
            'kilogramos' => ['required', 'numeric', 'gt:0'],
            'libras' => ['required', 'numeric', 'gt:0'],
            'metros' => ['required', 'numeric', 'gt:0'],
            'yardas' => ['required', 'numeric', 'gt:0'],
            'tara' => ['required', 'numeric', 'min:0'],
            'pbruto' => ['required', 'numeric', 'gte:kilogramos'],
        ]);

        $clave = (int) (ltrim($v['rollo'], '0') ?: '0');
        $pneto = round((float) $v['kilogramos'], 3);
        $tara = round((float) $v['tara'], 3);
        $pbrut = round((float) $v['pbruto'], 3);
        $metros = round((float) $v['metros'], 3);

        if (abs(($pneto + $tara) - $pbrut) > 0.01) {
            return response()->json(['ok' => false, 'motivo' => 'pesos_inconsistentes'], 422);
        }

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                return $bloqueo;
            }

            $afectados = $conn->update(
                'UPDATE PSDTABPZAS
                SET PESOKG = ?, PNETO = ?, PESOLB = ?, MTS = ?, LONGITUD = ?, YDS = ?,
                    TARA = ?, PBRUT = ?
              WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0',
                [$pneto, $pneto, round((float) $v['libras'], 3), $metros, $metros,
                    round((float) $v['yardas'], 3), $tara, $pbrut, $clave]
            );
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] UPDATE', ['clave' => $clave, 'm' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'No se pudo guardar'], 500);
        }

        if ($afectados < 1) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_eliminado', 'error' => 'El rollo ya fue eliminado'], 409);
        }

        return response()->json(['ok' => true]);
    }

    public function eliminar(Request $request)
    {
        $v = $request->validate([
            'rollo' => ['required', 'regex:/^\d{1,10}$/'],
        ]);

        $clave = (int) (ltrim($v['rollo'], '0') ?: '0');

        $usuario = auth()->user();
        $userId = (int) $usuario->ID;

        $identity = UserFirebirdIdentity::where('firebird_user_clave', $userId)->first()
            ?? UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();

        $tbClave = $identity?->firebird_tb_clave;
        $tbClave = is_string($tbClave) ? trim($tbClave) : $tbClave;

        if (! $tbClave) {
            return response()->json(['ok' => false, 'error' => 'Usuario sin clave de empleado (TB)'], 403);
        }

        try {
            $conn = $this->firebird->getProductionConnection();

            if ($bloqueo = $this->validarEstatus($conn, $clave)) {
                return $bloqueo;
            }

            $afectados = $conn->update(
                'UPDATE PSDTABPZAS
                SET ESTATUS = 0,
                    FECHAYHORADEL = CURRENT_TIMESTAMP,
                    USDEL = ?,
                    CASEDEL = 1
              WHERE CLAVE = ? AND COALESCE(ESTATUS, 1) <> 0',
                [$tbClave, $clave]
            );
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] ELIMINAR', [
                'clave' => $clave,
                'tb' => $tbClave,
                'm' => $e->getMessage(),
            ]);

            return response()->json(['ok' => false, 'error' => 'No se pudo eliminar'], 500);
        }

        if ($afectados < 1) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_eliminado', 'error' => 'El rollo ya fue eliminado'], 409);
        }

        return response()->json(['ok' => true]);
    }

    private function encabezado($connection, $cveEnc): ?array
    {
        return Cache::remember("ca:enc2:$cveEnc", 21600, function () use ($connection, $cveEnc) {
            $e = $connection->selectOne("
            SELECT P.ARTICULO AS ARTICULO, P.PARTIDA AS OP, OE.ORDEN AS ORDEN, OE.ID AS OE_ID
            FROM P_PSDENC('".config('firebird.company')."') P
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            WHERE P.CVE_PSD_ENC = ?
        ", [$cveEnc]);

            return $e ? (array) $e : null;
        });
    }

    private function estatusOrden($connection, $oeId): ?int
    {
        if (! $oeId) {
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
            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

        $psd = (array) $psd;

        // NUEVO: rollo ya eliminado
        if ($psd['ESTATUS'] !== null && (int) $psd['ESTATUS'] === 0) {
            return response()->json([
                'ok' => false,
                'motivo' => 'rollo_eliminado',
                'error' => 'El rollo ya fue eliminado',
            ], 409);
        }

        $enc = $this->encabezado($connection, $psd['CVE_ENC']);
        $estatus = $this->estatusOrden($connection, $enc['OE_ID'] ?? null);

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
}
