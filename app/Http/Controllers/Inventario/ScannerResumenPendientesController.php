<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScannerResumenPendientesController extends Controller
{
    private const CHUNK = 500; // Firebird limita el IN a 1500 items

    public function __construct(
        protected FirebirdConnectionService $firebird,
    ) {}

    /**
     * POST: { "codigos": ["0000259006", ...] }
     * Calcula cuántos escaneos sin guardar hay y cuántos kilos suman.
     */
    public function resumen(Request $request)
    {
        $request->validate([
            'codigos'   => 'required|array|min:1|max:2000',
            'codigos.*' => 'required|string',
        ]);

        // ── 1. Normalizar: solo códigos de 10 dígitos, sin repetidos ──────────
        $invalidos = [];
        $claves = []; // clave int => código original (con ceros)

        foreach ($request->codigos as $raw) {
            $codigo = trim($raw);

            if (!preg_match('/^\d{10}$/', $codigo)) {
                $invalidos[] = $codigo;
                continue;
            }

            $clave = (int) ltrim($codigo, '0');
            if ($clave <= 0) {
                $invalidos[] = $codigo;
                continue;
            }

            $claves[$clave] = $codigo;
        }

        // ── 2. Consultar en lotes ─────────────────────────────────────────────
        $items = []; // indexado por clave para evitar duplicados por los LEFT JOIN

        try {
            $connection = $this->firebird->getProductionConnection();

            foreach (array_chunk(array_keys($claves), self::CHUNK) as $lote) {
                $placeholders = implode(',', array_fill(0, count($lote), '?'));

                $rows = $connection->select($this->sqlAcabado($placeholders), $lote);

                foreach ($rows as $r) {
                    $r = (array) $r;
                    $id = (int) $r['ID'];

                    // Si un join multiplica filas, nos quedamos con la primera
                    // (así no se inflan los kilos).
                    if (!isset($items[$id])) {
                        $items[$id] = $r;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('❌ [resumenPendientes] Falló la consulta', [
                'message' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'error' => 'No se pudo calcular el resumen'], 500);
        }

        // ── 3. Armar respuesta y totales ──────────────────────────────────────
        $detalle = [];
        $noEncontrados = [];
        $totalKilos = 0.0;
        $porTipo = [];

        foreach ($claves as $clave => $codigo) {
            if (!isset($items[$clave])) {
                $noEncontrados[] = $codigo;
                continue;
            }

            $row = $items[$clave];
            $row['CODIGO'] = $codigo;
            $peso = (float) ($row['PESO NETO'] ?? 0);

            $totalKilos += $peso;

            $tipo = $row['TIPO'] ?? 'OTRAS';
            $porTipo[$tipo] ??= ['piezas' => 0, 'kilos' => 0.0];
            $porTipo[$tipo]['piezas']++;
            $porTipo[$tipo]['kilos'] += $peso;

            $detalle[] = $row;
        }

        foreach ($porTipo as &$t) {
            $t['kilos'] = round($t['kilos'], 3);
        }
        unset($t);

        return response()->json([
            'ok'             => true,
            'total_escaneos' => count($claves),
            'total_piezas'   => count($detalle),
            'total_kilos'    => round($totalKilos, 3),
            'por_tipo'       => $porTipo,
            'detalle'        => $detalle,
            'no_encontrados' => $noEncontrados,
            'invalidos'      => $invalidos,
        ]);
    }

    private function sqlAcabado(string $placeholders): string
    {
        return "
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
            INNER JOIN P_PSDENC('".config('firebird.company')."') P ON P.CVE_PSD_ENC = PSD.CVE_ENC
            LEFT JOIN ORDENESENC OE ON OE.ID = P.CVE_ORDEN
            LEFT JOIN p_vendxx('".config('firebird.company')."') V ON V.id = OE.agente
            LEFT JOIN ORDENESPROC R ON R.ORDEN = OE.ORDEN AND R.ST = 1
            LEFT JOIN PROCESOS S ON S.CODIGO = R.PROC
            LEFT JOIN DEPTOS D ON D.CLAVE = S.DEPTO
            LEFT JOIN ORDENESest E ON E.ID = OE.ESTATUS
            WHERE PSD.CLAVE IN ($placeholders)
        ";
    }
}