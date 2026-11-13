<?php

namespace App\Http\Controllers\Scanner;

use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ConrrecionAcabadoController extends Controller
{
    protected FirebirdConnectionService $firebird;

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
        $rollo = trim((string) $request->rollo);

        if (! preg_match('/^\d{1,10}$/', $rollo)) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_invalido', 'rollo' => $rollo], 422);
        }

        $clave = (int) (ltrim($rollo, '0') ?: '0');

        try {
            $datos = $this->datosRolloAcabado($this->firebird->getProductionConnection(), $clave);
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] scan', ['m' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Conexión Firebird fallida'], 500);
        }

        if ($datos === null) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado', 'rollo' => $rollo], 404);
        }

        return response()->json([
            'ok' => true,
            'rollo' => $rollo,
            'pieza' => $datos['PIEZA'],
            'op' => trim((string) $datos['OP']),
            'peso' => (float) $datos['PESO NETO'],
            'datos' => $datos,
        ]);
    }

    private function datosRolloAcabado($connection, int $clave): ?array
    {
        try {
            // 1) Rollo directo por CLAVE (rápido)
            $row = $connection->selectOne("
            SELECT
                PSD.CLAVE AS ID,
                PSD.CVE_ENC AS CVE_ENC,
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

            // 2) Encabezado de la partida, cacheado (la parte lenta)
            $cveEnc = $row['CVE_ENC'];
            $enc = Cache::remember("ca:enc:$cveEnc", 21600, function () use ($connection, $cveEnc) {
                $e = $connection->selectOne("
                SELECT P.ARTICULO AS ARTICULO, P.PARTIDA AS OP, OE.ORDEN AS ORDEN
                FROM P_PSDENC('".config('firebird.company')."') P
                LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
                WHERE P.CVE_PSD_ENC = ?
            ", [$cveEnc]);

                return $e ? (array) $e : null;
            });

            if (! $enc) {
                return null;
            }

            $row = array_merge($row, $enc);
            unset($row['CVE_ENC']);

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
            $afectados = $this->firebird->getProductionConnection()->update(
                'UPDATE PSDTABPZAS
                SET PESOKG = ?, PNETO = ?, PESOLB = ?, MTS = ?, LONGITUD = ?, YDS = ?,
                    TARA = ?, PBRUT = ?
              WHERE CLAVE = ?',
                [$pneto, $pneto, round((float) $v['libras'], 3), $metros, $metros,
                    round((float) $v['yardas'], 3), $tara, $pbrut, $clave]
            );
        } catch (\Throwable $e) {
            Log::error('[correccion-acabado] UPDATE', ['clave' => $clave, 'm' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'No se pudo guardar'], 500);
        }

        if ($afectados < 1) {
            return response()->json(['ok' => false, 'motivo' => 'rollo_no_encontrado'], 404);
        }

        return response()->json(['ok' => true]);
    }
}
