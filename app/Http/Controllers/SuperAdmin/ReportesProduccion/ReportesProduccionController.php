<?php

namespace App\Http\Controllers\SuperAdmin\ReportesProduccion;

use App\Events\ReportesActualizados;
use App\Http\Controllers\Controller;
use App\Models\Ocultar;
use App\Models\UserFirebirdIdentity;
use DateTime;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ReportesProduccionController extends Controller
{
    /**
     * Departamentos excluidos del reporte
     */
    private $departamentosExcluidos = [
        'ACABADO',
        'ACABADO TUBULAR',
        'ALMACEN TELA ACABADA PT',
        'CONTROL DE CALIDAD',
        'PROGRAMACION Y PLANEACION',
    ];


    /**
     * Display a listing of the resource with date filters.
     */
    public function index(Request $request)
    {
        try {
            // Validar parámetros de entrada
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'nullable|string',
                'fecha_fin' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Validar formato de fechas Firebird
            if ($fechaInicio && $fechaFin) {
                if (
                    ! $this->validarFormatoFechaFirebird($fechaInicio) ||
                    ! $this->validarFormatoFechaFirebird($fechaFin)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
                    ], 400);
                }
            }

            // Consulta a Firebird con filtros de fecha
            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
                ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
                ->select(
                    'd.DEPTO as departamento',
                    'p.PROCESO as proceso',
                    DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD')
                );

            // ✅ Excluir TEJIDO y departamentos específicos
            $query->where(function ($q) {
                $q->where('p.PROCESO', '<>', 'TEJIDO')
                    ->where('d.DEPTO', '<>', 'TEJIDO');
            });

            // 🔥 NUEVO: Excluir departamentos adicionales
            $query->whereNotIn('d.DEPTO', $this->departamentosExcluidos);

            // Filtrar por fechas si vienen
            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $reportes = $query
                ->groupBy(
                    'd.DEPTO',
                    'p.PROCESO'
                )
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reportes,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'total_registros' => $reportes->count(),
                    'departamentos_excluidos' => $this->departamentosExcluidos,
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes de producción',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // private function getFacturadoData($fechaInicio, $fechaFin)
    // {
    //     // $fechaInicioISO    = substr($fechaInicio, 0, 10);
    //     // $fechaFinISO       = substr($fechaFin, 0, 10);
    //     // $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFinISO . ' +1 day'));


    //     Log::info('getFacturadoData - fechas RAW', [
    //         'fechaInicio_raw' => $fechaInicio,
    //         'fechaFin_raw'    => $fechaFin,
    //     ]);

    //     $fechaInicioISO = \DateTime::createFromFormat('d.m.Y H:i:s', $fechaInicio)?->format('Y-m-d')
    //         ?? substr($fechaInicio, 0, 10);
    //     $fechaFinISO    = \DateTime::createFromFormat('d.m.Y H:i:s', $fechaFin)?->format('Y-m-d')
    //         ?? substr($fechaFin, 0, 10);
    //     $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFinISO . ' +1 day'));

    //     Log::info('getFacturadoData - fechas CALCULADAS', [
    //         'fechaInicioISO'    => $fechaInicioISO,
    //         'fechaFinISO'       => $fechaFinISO,
    //         'fechaFinExclusiva' => $fechaFinExclusiva,
    //     ]);


    //     $sql = "
    //             SELECT
    //                 F.FECHA_DOC AS FECHA,
    //                 CASE EXTRACT(MONTH FROM F.FECHA_DOC)
    //                     WHEN 1  THEN 'ENERO'
    //                     WHEN 2  THEN 'FEBRERO'
    //                     WHEN 3  THEN 'MARZO'
    //                     WHEN 4  THEN 'ABRIL'
    //                     WHEN 5  THEN 'MAYO'
    //                     WHEN 6  THEN 'JUNIO'
    //                     WHEN 7  THEN 'JULIO'
    //                     WHEN 8  THEN 'AGOSTO'
    //                     WHEN 9  THEN 'SEPTIEMBRE'
    //                     WHEN 10 THEN 'OCTUBRE'
    //                     WHEN 11 THEN 'NOVIEMBRE'
    //                     WHEN 12 THEN 'DICIEMBRE'
    //                 END AS MES,
    //                 C.NOMBRE AS CLIENTE,
    //                 CAST(F.FOLIO AS VARCHAR(20)) AS FACTURA,
    //                 '-' AS REMISION,
    //                 F.CVE_PEDI AS PEDIDO,
    //                 I.LIN_PROD AS LINEA_PRODUCTO,
    //                 'Z100' AS MODALIDAD,
    //                 'COMERCIALIZADORA FIBRASAN' AS EMPRESA,
    //                 'TELA' AS CLASIFICACION_PRODUCTO,
    //                 COALESCE(T.CODIGO || ' ' || AR.NOMBRE, I.CVE_ART) AS DESCRIPCION_PRODUCTO,
    //                 COALESCE(COMP.CADCOMP || ' ' || HI.CODIGO, '-') AS COMPOSICION,
    //                 (SELECT FIRST 1 ACB2.DESCR
    //                 FROM ACABTIPO ACB2
    //                 WHERE ACB2.TIPO = AR.TIPOACAB
    //                 AND ACB2.ESTAMPADO = 0) AS COMPOSICION2,
    //                 P.CANT AS KG,
    //                 P.UNI_VENTA AS UM,
    //                 CAST(P.PREC AS NUMERIC(18,2)) AS PRECIO_BRUTO,
    //                 CAST(P.CANT * P.PREC AS NUMERIC(18,2)) AS SUBTOTAL,
    //                 CAST(P.TOTIMP4 AS NUMERIC(18,2)) AS IVA,
    //                P.CANT * P.PREC AS TOTAL
    //             FROM FACTF03 F
    //             INNER JOIN PAR_FACTF03   P    ON P.CVE_DOC  = F.CVE_DOC
    //             INNER JOIN CLIE03        C    ON C.CLAVE    = F.CVE_CLPV
    //             INNER JOIN INVE03        I    ON I.CVE_ART  = P.CVE_ART
    //             LEFT  JOIN ARTICULOS     AR   ON AR.CVE_ART = I.CVE_ART
    //             LEFT  JOIN TEJIDO        T    ON T.ID       = AR.TEJ
    //             LEFT  JOIN COMPOSICION   COMP ON COMP.ID    = AR.COMP
    //             LEFT  JOIN HILATURA      HI   ON HI.ID      = AR.HILAT
    //             LEFT  JOIN OBS_DOCF03    O    ON O.CVE_OBS  = P.CVE_OBS
    //             WHERE
    //                 F.STATUS        = 'E'
    //                 AND F.fecha_doc >= ?
    //                 AND F.fecha_doc <= ?
    //                 AND P.CANT       > 0
    //                 AND I.LIN_PROD IN ('HILOS', 'PTPR')
    //                  AND F.TIP_DOC_SIG IS NULL
    //             ORDER BY F.FECHA_DOC, F.FOLIO, P.NUM_PAR
    //     ";

    //     //AND NOT EXISTS (
    //     //     SELECT 1
    //     //     FROM FACTD03 FD
    //     //     INNER JOIN PAR_FACTD03 PD ON PD.CVE_DOC = FD.CVE_DOC
    //     //     WHERE FD.CVE_DOC  = F.DOC_SIG
    //     //     AND FD.STATUS   = 'E'
    //     //     AND PD.CVE_ART  = P.CVE_ART
    //     //     AND PD.CANT     = P.CANT
    //     // )

    //     $sqlNotasVentaTotal = "
    //         SELECT
    //             F.CVE_DOC,
    //             F.IMPORTE
    //         FROM FACTV03 F
    //         WHERE F.STATUS = 'E'
    //         AND F.FECHA_DOC >= ?
    //         AND F.FECHA_DOC < ?
    //     ";

    //     $sqlNotasVentaCant = "
    //         SELECT
    //             P.CANT,
    //             P.UNI_VENTA,
    //             F.CAN_TOT,
    //             F.IMPORTE,
    //             I.LIN_PROD AS LINEA_PRODUCTO
    //         FROM FACTV03 F
    //         INNER JOIN PAR_FACTV03 P ON F.CVE_DOC = P.CVE_DOC
    //         INNER JOIN INVE03 I ON I.CVE_ART = P.CVE_ART
    //         WHERE F.STATUS = 'E'
    //         AND F.FECHA_DOC >= ?
    //         AND F.FECHA_DOC < ?
    //     ";

    //     $sqlNotasVentaPorDia = "
    //         SELECT
    //             CAST(F.FECHA_DOC AS DATE)                     AS FECHA,
    //             I.LIN_PROD                                    AS LINEA_PRODUCTO,
    //             COUNT(DISTINCT F.CVE_DOC)                     AS REGISTROS,
    //             CAST(SUM(F.IMPORTE) AS NUMERIC(18,2))         AS TOTAL_NV,
    //             P.UNI_VENTA                                   AS UM,
    //             CAST(SUM(P.CANT) AS NUMERIC(18,2))            AS CANT
    //         FROM FACTV03 F
    //         INNER JOIN PAR_FACTV03 P ON F.CVE_DOC = P.CVE_DOC
    //         INNER JOIN INVE03      I ON I.CVE_ART = P.CVE_ART
    //         WHERE F.STATUS = 'E'
    //         AND F.FECHA_DOC >= ?
    //         AND F.FECHA_DOC <= ?
    //         GROUP BY CAST(F.FECHA_DOC AS DATE), I.LIN_PROD, P.UNI_VENTA
    //         ORDER BY CAST(F.FECHA_DOC AS DATE) ASC
    //     ";

    //     $rows = DB::connection('firebird')->select($sql, [$fechaInicioISO, $fechaFinISO]);
    //     $rowsNotasVentaTotal = DB::connection('firebird')->select(
    //         $sqlNotasVentaTotal,
    //         [$fechaInicioISO, $fechaFinExclusiva]
    //     );

    //     $rowsNotasVentaCant = DB::connection('firebird')->select(
    //         $sqlNotasVentaCant,
    //         [$fechaInicioISO, $fechaFinExclusiva]
    //     );

    //     // ── Mapeo del detalle ─────────────────────────────────────────────────────
    //     $detalle = array_map(function ($r) {
    //         return [
    //             'fecha'                  => $r->FECHA                  ?? null,
    //             'mes'                    => $r->MES                    ?? null,
    //             'cliente'                => $r->CLIENTE                ?? null,
    //             'factura'                => $r->FACTURA                ?? null,
    //             'remision'               => $r->REMISION               ?? '-',
    //             'pedido'                 => $r->PEDIDO                 ?? null,
    //             'linea_producto'         => $r->LINEA_PRODUCTO         ?? null,  // ← AGREGADO
    //             'modalidad'              => $r->MODALIDAD              ?? null,
    //             'empresa'                => $r->EMPRESA                ?? null,
    //             'clasificacion_producto' => $r->CLASIFICACION_PRODUCTO ?? null,
    //             'descripcion_producto'   => $r->DESCRIPCION_PRODUCTO   ?? null,
    //             'color'                  => $r->COLOR                  ?? null,
    //             'composicion'            => $r->COMPOSICION            ?? null,
    //             'composicion2'           => $r->COMPOSICION2           ?? null,
    //             'precio_bruto'           => (float) ($r->PRECIO_BRUTO  ?? 0),
    //             'iva'                    => (float) ($r->IVA            ?? 0),
    //             'impuestos'              => (float) ($r->IVA            ?? 0),
    //             'total'                  => (float) ($r->TOTAL          ?? 0),
    //             'importe'                => (float) ($r->SUBTOTAL       ?? 0),
    //             'um'                     => $r->UM                     ?? null,
    //             'cant'                   => (float) ($r->KG             ?? 0),
    //         ];
    //     }, $rows);

    //     // ← AGREGA AQUÍ
    //     $lbRows = array_filter($detalle, fn($i) => in_array(strtoupper(trim($i['um'] ?? '')), ['LB', 'LBS']));
    //     Log::info('LB rows PTPR', [
    //         'count'          => count($lbRows),
    //         'cant_total_lb'  => array_sum(array_column(array_values($lbRows), 'cant')),
    //         'cant_kg_eq'     => array_sum(array_column(array_values($lbRows), 'cant')) * 0.453592,
    //         'muestra_um'     => array_unique(array_column(array_values($lbRows), 'um')), // ver cómo viene el string
    //     ]);

    //     // ── Facturas únicas ───────────────────────────────────────────────────────
    //     $facturas = [];
    //     foreach ($rows as $r) {
    //         $fac = $r->FACTURA ?? null;
    //         if (!$fac) continue;
    //         if (!isset($facturas[$fac])) {
    //             $facturas[$fac] = [
    //                 'importe' => (float) ($r->SUBTOTAL ?? 0),
    //                 'iva'     => (float) ($r->IVA      ?? 0),
    //                 'total'   => (float) ($r->TOTAL    ?? 0),
    //             ];
    //         }
    //     }

    //     // ── Totales generales ─────────────────────────────────────────────────────
    //     $totalKg       = array_sum(array_column($detalle, 'cant'));
    //     $totalSubtotal = array_sum(array_column($detalle, 'importe'));
    //     $totalIva      = array_sum(array_column($detalle, 'iva'));
    //     $totalGeneral  = array_sum(array_column($detalle, 'total'));

    //     // ── Totales por línea (PTPR / HILOS) ─────────────────────────────────────
    //     $LB_TO_KG = 0.453592;

    //     $totalesPorLinea = [];
    //     foreach ($detalle as $item) {
    //         $linea = $item['linea_producto'] ?? 'SIN_LINEA';
    //         $um    = strtoupper(trim($item['um'] ?? ''));
    //         $cant  = (float) ($item['cant'] ?? 0);

    //         if (!isset($totalesPorLinea[$linea])) {
    //             $totalesPorLinea[$linea] = [
    //                 'cant'       => 0.0,   // suma cruda original (legacy, no tocar)
    //                 'cant_kg'    => 0.0,   // registros con UM = KG/KGS
    //                 'cant_lb'    => 0.0,   // registros con UM = LB/LBS
    //                 'cant_kg_eq' => 0.0,   // todo convertido a KG
    //                 'importe'    => 0.0,
    //                 'impuestos'  => 0.0,
    //                 'total'      => 0.0,
    //             ];
    //         }

    //         if (in_array($um, ['LB', 'LBS'])) {
    //             $totalesPorLinea[$linea]['cant_lb']    += $cant;
    //             $totalesPorLinea[$linea]['cant_kg_eq'] += $cant * $LB_TO_KG;
    //         } else {
    //             $totalesPorLinea[$linea]['cant_kg']    += $cant;
    //             $totalesPorLinea[$linea]['cant_kg_eq'] += $cant;
    //         }

    //         $totalesPorLinea[$linea]['cant']      += $cant;
    //         $totalesPorLinea[$linea]['importe']   += $item['importe'];
    //         $totalesPorLinea[$linea]['impuestos'] += $item['impuestos'];
    //         $totalesPorLinea[$linea]['total']     += $item['total'];
    //     }

    //     foreach ($totalesPorLinea as $linea => $vals) {
    //         $totalesPorLinea[$linea]['cant']       = round($vals['cant'],       2);
    //         $totalesPorLinea[$linea]['cant_kg']    = round($vals['cant_kg'],    2);
    //         $totalesPorLinea[$linea]['cant_lb']    = round($vals['cant_lb'],    2);
    //         $totalesPorLinea[$linea]['cant_kg_eq'] = round($vals['cant_kg_eq'], 2);
    //         $totalesPorLinea[$linea]['importe']    = round($vals['importe'],    2);
    //         $totalesPorLinea[$linea]['impuestos']  = round($vals['impuestos'],  2);
    //         $totalesPorLinea[$linea]['total']      = round($vals['total'],      2);
    //     }

    //     // ── Notas de venta ────────────────────────────────────────────────────────
    //     $totalNotasVenta = array_sum(
    //         array_map(fn($r) => (float) ($r->IMPORTE ?? 0), $rowsNotasVentaTotal)
    //     );

    //     $rowsNVporDia = DB::connection('firebird')->select(
    //         $sqlNotasVentaPorDia,
    //         [$fechaInicioISO, $fechaFinISO]
    //     );
    //     $unidades       = [];   // totales por unidad de medida (sin cambio)
    //     $notasPorLinea  = [];   // ← NUEVO: totales por línea PTPR / HILOS

    //     foreach ($rowsNotasVentaCant as $r) {
    //         $um    = $r->UNI_VENTA      ?? 'SIN_UM';
    //         $linea = $r->LINEA_PRODUCTO ?? 'SIN_LINEA';
    //         if ($linea === 'PTPR') {
    //             $cant = (float) ($r->CANT ?? 0);     // peso real
    //         } else {
    //             $cant = (float) ($r->CAN_TOT ?? 0);  // piezas / total
    //         }
    //         $imp   = (float) ($r->IMPORTE ?? 0);

    //         // — por unidad de medida (ya existía) —
    //         if (!isset($unidades[$um])) {
    //             $unidades[$um] = ['um' => $um, 'cant' => 0];
    //         }
    //         $unidades[$um]['cant'] += $cant;

    //         // — por línea de producto (NUEVO) —
    //         if (!isset($notasPorLinea[$linea])) {
    //             $notasPorLinea[$linea] = [
    //                 'cant'  => 0.0,
    //                 'total' => 0.0,
    //             ];
    //         }
    //         $notasPorLinea[$linea]['cant']  += $cant;
    //         $notasPorLinea[$linea]['total'] += $imp;
    //     }

    //     // Redondear
    //     foreach ($notasPorLinea as $linea => $vals) {
    //         $notasPorLinea[$linea]['cant']  = round($vals['cant'],  2);
    //         $notasPorLinea[$linea]['total'] = round($vals['total'], 2);
    //     }

    //     $notasVentaPorDia = [];
    //     foreach ($rowsNVporDia as $r) {
    //         $fecha = substr($r->FECHA ?? '', 0, 10);
    //         $linea = $r->LINEA_PRODUCTO ?? 'SIN_LINEA';

    //         if (!isset($notasVentaPorDia[$fecha])) {
    //             $notasVentaPorDia[$fecha] = [
    //                 'registros' => 0,
    //                 'total_nv'  => 0.0,
    //                 'unidades'  => [],
    //                 'por_linea' => [],   // ← NUEVO
    //             ];
    //         }

    //         $notasVentaPorDia[$fecha]['registros'] += (int)   ($r->REGISTROS ?? 0);
    //         $notasVentaPorDia[$fecha]['total_nv']  += (float) ($r->TOTAL_NV  ?? 0);

    //         $notasVentaPorDia[$fecha]['unidades'][] = [
    //             'um'   => $r->UM   ?? 'N/A',
    //             'cant' => (float) ($r->CANT ?? 0),
    //         ];

    //         // ← NUEVO: acumular por línea dentro del día
    //         if (!isset($notasVentaPorDia[$fecha]['por_linea'][$linea])) {
    //             $notasVentaPorDia[$fecha]['por_linea'][$linea] = [
    //                 'cant'  => 0.0,
    //                 'total' => 0.0,
    //             ];
    //         }
    //         $notasVentaPorDia[$fecha]['por_linea'][$linea]['cant']  += (float) ($r->CANT     ?? 0);
    //         $notasVentaPorDia[$fecha]['por_linea'][$linea]['total'] += (float) ($r->TOTAL_NV ?? 0);
    //     }

    //     // Redondear por_linea dentro de cada día
    //     foreach ($notasVentaPorDia as $fecha => $data) {
    //         foreach ($data['por_linea'] as $linea => $vals) {
    //             $notasVentaPorDia[$fecha]['por_linea'][$linea]['cant']  = round($vals['cant'],  2);
    //             $notasVentaPorDia[$fecha]['por_linea'][$linea]['total'] = round($vals['total'], 2);
    //         }
    //         $notasVentaPorDia[$fecha]['total_nv'] = round($data['total_nv'], 2);
    //     }

    //     $unidades = array_values($unidades);

    //     return [
    //         'totales' => [
    //             'facturas'  => count($facturas),
    //             'cant'      => round((float) $totalKg,       2),
    //             'importe'   => round((float) $totalSubtotal, 2),
    //             'impuestos' => round((float) $totalIva,      2),
    //             'total'     => round((float) $totalGeneral,  2),
    //         ],
    //         'por_linea'           => $totalesPorLinea,      // ← PTPR y HILOS desglosados
    //         'notas_venta' => [
    //             'registros' => count($rowsNotasVentaTotal),
    //             'total'     => $totalNotasVenta,
    //             'unidades'  => $unidades,
    //             'por_linea' => $notasPorLinea,
    //         ],
    //         'notas_venta_por_dia' => $notasVentaPorDia,
    //         'detalle'             => $detalle,
    //         'filtros' => [
    //             'fecha_inicio'        => $fechaInicioISO,
    //             'fecha_fin'           => $fechaFinISO,
    //             'fecha_fin_exclusiva' => $fechaFinExclusiva,
    //             'total_registros'     => count($rows),
    //         ],
    //     ];
    // }


    private function getFacturadoData($fechaInicio, $fechaFin)
    {
        // $fechaInicioISO    = substr($fechaInicio, 0, 10);
        // $fechaFinISO       = substr($fechaFin, 0, 10);
        // $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFinISO . ' +1 day'));


        Log::info('getFacturadoData - fechas RAW', [
            'fechaInicio_raw' => $fechaInicio,
            'fechaFin_raw'    => $fechaFin,
        ]);

        $fechaInicioISO = \DateTime::createFromFormat('d.m.Y H:i:s', $fechaInicio)?->format('Y-m-d')
            ?? substr($fechaInicio, 0, 10);
        $fechaFinISO    = \DateTime::createFromFormat('d.m.Y H:i:s', $fechaFin)?->format('Y-m-d')
            ?? substr($fechaFin, 0, 10);
        $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFinISO . ' +1 day'));

        Log::info('getFacturadoData - fechas CALCULADAS', [
            'fechaInicioISO'    => $fechaInicioISO,
            'fechaFinISO'       => $fechaFinISO,
            'fechaFinExclusiva' => $fechaFinExclusiva,
        ]);


        $sql = "
                SELECT
                    F.FECHA_DOC AS FECHA,
                    CASE EXTRACT(MONTH FROM F.FECHA_DOC)
                        WHEN 1  THEN 'ENERO'
                        WHEN 2  THEN 'FEBRERO'
                        WHEN 3  THEN 'MARZO'
                        WHEN 4  THEN 'ABRIL'
                        WHEN 5  THEN 'MAYO'
                        WHEN 6  THEN 'JUNIO'
                        WHEN 7  THEN 'JULIO'
                        WHEN 8  THEN 'AGOSTO'
                        WHEN 9  THEN 'SEPTIEMBRE'
                        WHEN 10 THEN 'OCTUBRE'
                        WHEN 11 THEN 'NOVIEMBRE'
                        WHEN 12 THEN 'DICIEMBRE'
                    END AS MES,
                    C.NOMBRE AS CLIENTE,
                    CAST(F.FOLIO AS VARCHAR(20)) AS FACTURA,
                    '-' AS REMISION,
                    F.CVE_PEDI AS PEDIDO,
                    I.LIN_PROD AS LINEA_PRODUCTO,
                    'Z100' AS MODALIDAD,
                    'COMERCIALIZADORA FIBRASAN' AS EMPRESA,
                    'TELA' AS CLASIFICACION_PRODUCTO,
                    COALESCE(T.CODIGO || ' ' || AR.NOMBRE, I.CVE_ART) AS DESCRIPCION_PRODUCTO,
                    COALESCE(COMP.CADCOMP || ' ' || HI.CODIGO, '-') AS COMPOSICION,
                    (SELECT FIRST 1 ACB2.DESCR
                    FROM ACABTIPO ACB2
                    WHERE ACB2.TIPO = AR.TIPOACAB
                    AND ACB2.ESTAMPADO = 0) AS COMPOSICION2,
                    P.CANT AS KG,
                    P.UNI_VENTA AS UM,
                    CAST(P.PREC AS NUMERIC(18,2)) AS PRECIO_BRUTO,
                    CAST(P.CANT * P.PREC AS NUMERIC(18,2)) AS SUBTOTAL,
                    CAST(P.TOTIMP4 AS NUMERIC(18,2)) AS IVA,
                   P.CANT * P.PREC AS TOTAL
                FROM FACTF03 F
                INNER JOIN PAR_FACTF03   P    ON P.CVE_DOC  = F.CVE_DOC
                INNER JOIN CLIE03        C    ON C.CLAVE    = F.CVE_CLPV
                INNER JOIN INVE03        I    ON I.CVE_ART  = P.CVE_ART
                LEFT  JOIN ARTICULOS     AR   ON AR.CVE_ART = I.CVE_ART
                LEFT  JOIN TEJIDO        T    ON T.ID       = AR.TEJ
                LEFT  JOIN COMPOSICION   COMP ON COMP.ID    = AR.COMP
                LEFT  JOIN HILATURA      HI   ON HI.ID      = AR.HILAT
                LEFT  JOIN OBS_DOCF03    O    ON O.CVE_OBS  = P.CVE_OBS
                WHERE
                    F.STATUS        = 'E'
                    AND F.fecha_doc >= ?
                    AND F.fecha_doc <= ?
                    AND P.CANT       > 0
                    AND I.LIN_PROD IN ('HILOS', 'PTPR')
                     
                ORDER BY F.FECHA_DOC, F.FOLIO, P.NUM_PAR
        ";
//AND F.TIP_DOC_SIG IS NULL

        //AND NOT EXISTS (
        //     SELECT 1
        //     FROM FACTD03 FD
        //     INNER JOIN PAR_FACTD03 PD ON PD.CVE_DOC = FD.CVE_DOC
        //     WHERE FD.CVE_DOC  = F.DOC_SIG
        //     AND FD.STATUS   = 'E'
        //     AND PD.CVE_ART  = P.CVE_ART
        //     AND PD.CANT     = P.CANT
        // )

        $sqlNotasVentaTotal = "
            SELECT
                F.CVE_DOC,
                F.IMPORTE
            FROM FACTV03 F
            WHERE F.STATUS = 'E'
            AND F.FECHA_DOC >= ?
            AND F.FECHA_DOC < ?
        ";

        $sqlNotasVentaCant = "
            SELECT
                P.CANT,
                P.UNI_VENTA,
                F.CAN_TOT,
                F.IMPORTE,
                I.LIN_PROD AS LINEA_PRODUCTO
            FROM FACTV03 F
            INNER JOIN PAR_FACTV03 P ON F.CVE_DOC = P.CVE_DOC
            INNER JOIN INVE03 I ON I.CVE_ART = P.CVE_ART
            WHERE F.STATUS = 'E'
            AND F.FECHA_DOC >= ?
            AND F.FECHA_DOC < ?
        ";

        $sqlNotasVentaPorDia = "
            SELECT
                CAST(F.FECHA_DOC AS DATE)                     AS FECHA,
                I.LIN_PROD                                    AS LINEA_PRODUCTO,
                COUNT(DISTINCT F.CVE_DOC)                     AS REGISTROS,
                CAST(SUM(F.IMPORTE) AS NUMERIC(18,2))         AS TOTAL_NV,
                P.UNI_VENTA                                   AS UM,
                CAST(SUM(P.CANT) AS NUMERIC(18,2))            AS CANT
            FROM FACTV03 F
            INNER JOIN PAR_FACTV03 P ON F.CVE_DOC = P.CVE_DOC
            INNER JOIN INVE03      I ON I.CVE_ART = P.CVE_ART
            WHERE F.STATUS = 'E'
            AND F.FECHA_DOC >= ?
            AND F.FECHA_DOC <= ?
            GROUP BY CAST(F.FECHA_DOC AS DATE), I.LIN_PROD, P.UNI_VENTA
            ORDER BY CAST(F.FECHA_DOC AS DATE) ASC
        ";


        $sqlDevoluciones = "
        SELECT
            FD.FECHA_DOC                                    AS FECHA,
            CASE EXTRACT(MONTH FROM FD.FECHA_DOC)
                WHEN 1  THEN 'ENERO'    WHEN 2  THEN 'FEBRERO'
                WHEN 3  THEN 'MARZO'    WHEN 4  THEN 'ABRIL'
                WHEN 5  THEN 'MAYO'     WHEN 6  THEN 'JUNIO'
                WHEN 7  THEN 'JULIO'    WHEN 8  THEN 'AGOSTO'
                WHEN 9  THEN 'SEPTIEMBRE' WHEN 10 THEN 'OCTUBRE'
                WHEN 11 THEN 'NOVIEMBRE'  WHEN 12 THEN 'DICIEMBRE'
            END                                             AS MES,
            C.NOMBRE                                        AS CLIENTE,
            CAST(FD.FOLIO AS VARCHAR(20))                   AS NOTA_CREDITO,
            CAST(F.FOLIO  AS VARCHAR(20))                   AS FACTURA_ORIGEN,
            I.LIN_PROD                                      AS LINEA_PRODUCTO,
            PD.CVE_ART                                      AS CVE_ART,
            COALESCE(T.CODIGO || ' ' || AR.NOMBRE, I.CVE_ART) AS DESCRIPCION_PRODUCTO,
            PD.CANT                                         AS CANT,
            PD.UNI_VENTA                                    AS UM,
            CAST(PD.PREC          AS NUMERIC(18,2))         AS PRECIO_BRUTO,
            CAST(PD.CANT * PD.PREC AS NUMERIC(18,2))        AS SUBTOTAL,
            CAST(PD.TOTIMP4       AS NUMERIC(18,2))         AS IVA,
            PD.CANT * PD.PREC                               AS TOTAL
        FROM FACTF03 F
        INNER JOIN FACTD03       FD   ON FD.CVE_DOC  = F.DOC_SIG
        INNER JOIN PAR_FACTD03   PD   ON PD.CVE_DOC  = FD.CVE_DOC
        INNER JOIN CLIE03        C    ON C.CLAVE      = FD.CVE_CLPV
        INNER JOIN INVE03        I    ON I.CVE_ART    = PD.CVE_ART
        LEFT  JOIN ARTICULOS     AR   ON AR.CVE_ART   = I.CVE_ART
        LEFT  JOIN TEJIDO        T    ON T.ID          = AR.TEJ
       WHERE
    FD.STATUS   = 'E'
    AND F.STATUS = 'E'
    AND F.FECHA_DOC >= ?
    AND F.FECHA_DOC <= ?
    AND PD.CANT       > 0
    AND I.LIN_PROD IN ('HILOS', 'PTPR')
        ORDER BY FD.FECHA_DOC, FD.FOLIO, PD.NUM_PAR
     ";

        $rows = DB::connection('firebird')->select($sql, [$fechaInicioISO, $fechaFinISO]);
        $rowsNotasVentaTotal = DB::connection('firebird')->select(
            $sqlNotasVentaTotal,
            [$fechaInicioISO, $fechaFinExclusiva]
        );

        $rowsNotasVentaCant = DB::connection('firebird')->select(
            $sqlNotasVentaCant,
            [$fechaInicioISO, $fechaFinExclusiva]
        );


        $rowsDevoluciones = DB::connection('firebird')->select(
            $sqlDevoluciones,
            [$fechaInicioISO, $fechaFinISO]
        );

        Log::info('devoluciones RAW', [
    'count' => count($rowsDevoluciones),
    'registros' => array_map(fn($r) => [
        'nota'           => $r->NOTA_CREDITO   ?? null,
        'factura_origen' => $r->FACTURA_ORIGEN ?? null,
        'fecha_fd'       => $r->FECHA          ?? null,
        'cant'           => $r->CANT           ?? null,
    ], $rowsDevoluciones),
]);

        // ── Mapeo del detalle ─────────────────────────────────────────────────────
        $detalle = array_map(function ($r) {
            return [
                'fecha'                  => $r->FECHA                  ?? null,
                'mes'                    => $r->MES                    ?? null,
                'cliente'                => $r->CLIENTE                ?? null,
                'factura'                => $r->FACTURA                ?? null,
                'remision'               => $r->REMISION               ?? '-',
                'pedido'                 => $r->PEDIDO                 ?? null,
                'linea_producto'         => $r->LINEA_PRODUCTO         ?? null,  // ← AGREGADO
                'modalidad'              => $r->MODALIDAD              ?? null,
                'empresa'                => $r->EMPRESA                ?? null,
                'clasificacion_producto' => $r->CLASIFICACION_PRODUCTO ?? null,
                'descripcion_producto'   => $r->DESCRIPCION_PRODUCTO   ?? null,
                'color'                  => $r->COLOR                  ?? null,
                'composicion'            => $r->COMPOSICION            ?? null,
                'composicion2'           => $r->COMPOSICION2           ?? null,
                'precio_bruto'           => (float) ($r->PRECIO_BRUTO  ?? 0),
                'iva'                    => (float) ($r->IVA            ?? 0),
                'impuestos'              => (float) ($r->IVA            ?? 0),
                'total'                  => (float) ($r->TOTAL          ?? 0),
                'importe'                => (float) ($r->SUBTOTAL       ?? 0),
                'um'                     => $r->UM                     ?? null,
                'cant'                   => (float) ($r->KG             ?? 0),
            ];
        }, $rows);

        // ← AGREGA AQUÍ
        $lbRows = array_filter($detalle, fn($i) => in_array(strtoupper(trim($i['um'] ?? '')), ['LB', 'LBS']));
        Log::info('LB rows PTPR', [
            'count'          => count($lbRows),
            'cant_total_lb'  => array_sum(array_column(array_values($lbRows), 'cant')),
            'cant_kg_eq'     => array_sum(array_column(array_values($lbRows), 'cant')) * 0.453592,
            'muestra_um'     => array_unique(array_column(array_values($lbRows), 'um')), // ver cómo viene el string
        ]);

        // ── Facturas únicas ───────────────────────────────────────────────────────
        $facturas = [];
        foreach ($rows as $r) {
            $fac = $r->FACTURA ?? null;
            if (!$fac) continue;
            if (!isset($facturas[$fac])) {
                $facturas[$fac] = [
                    'importe' => (float) ($r->SUBTOTAL ?? 0),
                    'iva'     => (float) ($r->IVA      ?? 0),
                    'total'   => (float) ($r->TOTAL    ?? 0),
                ];
            }
        }

        // __notas de devolucion_____________________________________________________

        $devoluciones = array_map(function ($r) {
            return [
                'fecha'               => $r->FECHA               ?? null,
                'mes'                 => $r->MES                 ?? null,
                'cliente'             => $r->CLIENTE             ?? null,
                'nota_credito'        => $r->NOTA_CREDITO        ?? null,
                'factura_origen'      => $r->FACTURA_ORIGEN      ?? null,
                'linea_producto'      => $r->LINEA_PRODUCTO      ?? null,
                'descripcion_producto' => $r->DESCRIPCION_PRODUCTO ?? null,
                'cant'                => (float) ($r->CANT       ?? 0),
                'um'                  => $r->UM                  ?? null,
                'precio_bruto'        => (float) ($r->PRECIO_BRUTO ?? 0),
                'importe'             => (float) ($r->SUBTOTAL   ?? 0),
                'iva'                 => (float) ($r->IVA        ?? 0),
                'total'               => (float) ($r->TOTAL      ?? 0),
            ];
        }, $rowsDevoluciones);

        // Totales de devoluciones por línea
        $devPorLinea = [];
        $LB_TO_KG = 0.453592;

        foreach ($devoluciones as $item) {
            $linea = $item['linea_producto'] ?? 'SIN_LINEA';
            $um    = strtoupper(trim($item['um'] ?? ''));
            $cant  = $item['cant'];

            if (!isset($devPorLinea[$linea])) {
                $devPorLinea[$linea] = ['cant_kg_eq' => 0.0, 'importe' => 0.0, 'total' => 0.0];
            }

            $devPorLinea[$linea]['cant_kg_eq'] += in_array($um, ['LB', 'LBS'])
                ? $cant * $LB_TO_KG
                : $cant;
            $devPorLinea[$linea]['importe']    += $item['importe'];
            $devPorLinea[$linea]['total']      += $item['total'];
        }

        // ── Totales generales ─────────────────────────────────────────────────────
        $totalKg       = array_sum(array_column($detalle, 'cant'));
        $totalSubtotal = array_sum(array_column($detalle, 'importe'));
        $totalIva      = array_sum(array_column($detalle, 'iva'));
        $totalGeneral  = array_sum(array_column($detalle, 'total'));

        // ── Totales por línea (PTPR / HILOS) ─────────────────────────────────────
        $LB_TO_KG = 0.453592;

        $totalesPorLinea = [];
        foreach ($detalle as $item) {
            $linea = $item['linea_producto'] ?? 'SIN_LINEA';
            $um    = strtoupper(trim($item['um'] ?? ''));
            $cant  = (float) ($item['cant'] ?? 0);

            if (!isset($totalesPorLinea[$linea])) {
                $totalesPorLinea[$linea] = [
                    'cant'       => 0.0,   // suma cruda original (legacy, no tocar)
                    'cant_kg'    => 0.0,   // registros con UM = KG/KGS
                    'cant_lb'    => 0.0,   // registros con UM = LB/LBS
                    'cant_kg_eq' => 0.0,   // todo convertido a KG
                    'importe'    => 0.0,
                    'impuestos'  => 0.0,
                    'total'      => 0.0,
                ];
            }

            if (in_array($um, ['LB', 'LBS'])) {
                $totalesPorLinea[$linea]['cant_lb']    += $cant;
                $totalesPorLinea[$linea]['cant_kg_eq'] += $cant * $LB_TO_KG;
            } else {
                $totalesPorLinea[$linea]['cant_kg']    += $cant;
                $totalesPorLinea[$linea]['cant_kg_eq'] += $cant;
            }

            $totalesPorLinea[$linea]['cant']      += $cant;
            $totalesPorLinea[$linea]['importe']   += $item['importe'];
            $totalesPorLinea[$linea]['impuestos'] += $item['impuestos'];
            $totalesPorLinea[$linea]['total']     += $item['total'];
        }

        foreach ($totalesPorLinea as $linea => $vals) {
            $totalesPorLinea[$linea]['cant']       = round($vals['cant'],       2);
            $totalesPorLinea[$linea]['cant_kg']    = round($vals['cant_kg'],    2);
            $totalesPorLinea[$linea]['cant_lb']    = round($vals['cant_lb'],    2);
            $totalesPorLinea[$linea]['cant_kg_eq'] = round($vals['cant_kg_eq'], 2);
            $totalesPorLinea[$linea]['importe']    = round($vals['importe'],    2);
            $totalesPorLinea[$linea]['impuestos']  = round($vals['impuestos'],  2);
            $totalesPorLinea[$linea]['total']      = round($vals['total'],      2);
        }

        // ── Notas de venta ────────────────────────────────────────────────────────
        $totalNotasVenta = array_sum(
            array_map(fn($r) => (float) ($r->IMPORTE ?? 0), $rowsNotasVentaTotal)
        );

        $rowsNVporDia = DB::connection('firebird')->select(
            $sqlNotasVentaPorDia,
            [$fechaInicioISO, $fechaFinISO]
        );
        $unidades       = [];   // totales por unidad de medida (sin cambio)
        $notasPorLinea  = [];   // ← NUEVO: totales por línea PTPR / HILOS

        foreach ($rowsNotasVentaCant as $r) {
            $um    = $r->UNI_VENTA      ?? 'SIN_UM';
            $linea = $r->LINEA_PRODUCTO ?? 'SIN_LINEA';
            if ($linea === 'PTPR') {
                $cant = (float) ($r->CANT ?? 0);     // peso real
            } else {
                $cant = (float) ($r->CAN_TOT ?? 0);  // piezas / total
            }
            $imp   = (float) ($r->IMPORTE ?? 0);

            // — por unidad de medida (ya existía) —
            if (!isset($unidades[$um])) {
                $unidades[$um] = ['um' => $um, 'cant' => 0];
            }
            $unidades[$um]['cant'] += $cant;

            // — por línea de producto (NUEVO) —
            if (!isset($notasPorLinea[$linea])) {
                $notasPorLinea[$linea] = [
                    'cant'  => 0.0,
                    'total' => 0.0,
                ];
            }
            $notasPorLinea[$linea]['cant']  += $cant;
            $notasPorLinea[$linea]['total'] += $imp;
        }

        // Redondear
        foreach ($notasPorLinea as $linea => $vals) {
            $notasPorLinea[$linea]['cant']  = round($vals['cant'],  2);
            $notasPorLinea[$linea]['total'] = round($vals['total'], 2);
        }

        $notasVentaPorDia = [];
        foreach ($rowsNVporDia as $r) {
            $fecha = substr($r->FECHA ?? '', 0, 10);
            $linea = $r->LINEA_PRODUCTO ?? 'SIN_LINEA';

            if (!isset($notasVentaPorDia[$fecha])) {
                $notasVentaPorDia[$fecha] = [
                    'registros' => 0,
                    'total_nv'  => 0.0,
                    'unidades'  => [],
                    'por_linea' => [],   // ← NUEVO
                ];
            }

            $notasVentaPorDia[$fecha]['registros'] += (int)   ($r->REGISTROS ?? 0);
            $notasVentaPorDia[$fecha]['total_nv']  += (float) ($r->TOTAL_NV  ?? 0);

            $notasVentaPorDia[$fecha]['unidades'][] = [
                'um'   => $r->UM   ?? 'N/A',
                'cant' => (float) ($r->CANT ?? 0),
            ];

            // ← NUEVO: acumular por línea dentro del día
            if (!isset($notasVentaPorDia[$fecha]['por_linea'][$linea])) {
                $notasVentaPorDia[$fecha]['por_linea'][$linea] = [
                    'cant'  => 0.0,
                    'total' => 0.0,
                ];
            }
            $notasVentaPorDia[$fecha]['por_linea'][$linea]['cant']  += (float) ($r->CANT     ?? 0);
            $notasVentaPorDia[$fecha]['por_linea'][$linea]['total'] += (float) ($r->TOTAL_NV ?? 0);
        }

        // Redondear por_linea dentro de cada día
        foreach ($notasVentaPorDia as $fecha => $data) {
            foreach ($data['por_linea'] as $linea => $vals) {
                $notasVentaPorDia[$fecha]['por_linea'][$linea]['cant']  = round($vals['cant'],  2);
                $notasVentaPorDia[$fecha]['por_linea'][$linea]['total'] = round($vals['total'], 2);
            }
            $notasVentaPorDia[$fecha]['total_nv'] = round($data['total_nv'], 2);
        }

        $unidades = array_values($unidades);

        return [
            'totales' => [
                'facturas'  => count($facturas),
                'cant'      => round((float) $totalKg,       2),
                'importe'   => round((float) $totalSubtotal, 2),
                'impuestos' => round((float) $totalIva,      2),
                'total'     => round((float) $totalGeneral,  2),
            ],
            'por_linea'           => $totalesPorLinea,      // ← PTPR y HILOS desglosados
            'notas_venta' => [
                'registros' => count($rowsNotasVentaTotal),
                'total'     => $totalNotasVenta,
                'unidades'  => $unidades,
                'por_linea' => $notasPorLinea,
            ],
            'devoluciones' => [
                'registros' => count($devoluciones),
                'fecha_inicio' => $fechaInicioISO,
                'fecha_fin'    => $fechaFinISO,
                'cant'         => round(array_sum(array_column($devoluciones, 'cant')),    2),
                'um' => array_values(array_unique(array_column($devoluciones, 'um'))), // ['KG', 'LB'] lo que sea que venga
                'subtotal'     => round(array_sum(array_column($devoluciones, 'importe')), 2),
                'iva'          => round(array_sum(array_column($devoluciones, 'iva')),     2),
                'total'        => round(array_sum(array_column($devoluciones, 'total')),   2),
                'por_linea'    => $devPorLinea,
                'detalle'      => $devoluciones,
            ],
            'notas_venta_por_dia' => $notasVentaPorDia,
            'detalle'             => $detalle,
            'filtros' => [
                'fecha_inicio'        => $fechaInicioISO,
                'fecha_fin'           => $fechaFinISO,
                'fecha_fin_exclusiva' => $fechaFinExclusiva,
                'total_registros'     => count($rows),
            ],
        ];
    }

    public function getFacturado(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors'  => $validator->errors(),
                ], 400);
            }

            $fechaInicio = DateTime::createFromFormat('d.m.Y H:i:s', $request->input('fecha_inicio'))?->format('Y-m-d')
                ?? substr($request->input('fecha_inicio'), 0, 10);
            $fechaFin = DateTime::createFromFormat('d.m.Y H:i:s', $request->input('fecha_fin'))?->format('Y-m-d')
                ?? substr($request->input('fecha_fin'), 0, 10);
            $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFin . ' +1 day'));

            $sql = "
            SELECT
                F.FECHA_DOC AS FECHA,
                CASE EXTRACT(MONTH FROM F.FECHA_DOC)
                    WHEN 1  THEN 'ENERO'
                    WHEN 2  THEN 'FEBRERO'
                    WHEN 3  THEN 'MARZO'
                    WHEN 4  THEN 'ABRIL'
                    WHEN 5  THEN 'MAYO'
                    WHEN 6  THEN 'JUNIO'
                    WHEN 7  THEN 'JULIO'
                    WHEN 8  THEN 'AGOSTO'
                    WHEN 9  THEN 'SEPTIEMBRE'
                    WHEN 10 THEN 'OCTUBRE'
                    WHEN 11 THEN 'NOVIEMBRE'
                    WHEN 12 THEN 'DICIEMBRE'
                END AS MES,
                C.NOMBRE AS CLIENTE,
                CAST(F.FOLIO AS VARCHAR(20)) AS FACTURA,
                '-' AS REMISION,
                F.CVE_PEDI AS PEDIDO,
                I.LIN_PROD AS LINEA_PRODUCTO,
                'Z100' AS MODALIDAD,
                'COMERCIALIZADORA FIBRASAN' AS EMPRESA,
                'TELA' AS CLASIFICACION_PRODUCTO,
                COALESCE(T.CODIGO || ' ' || AR.NOMBRE, I.CVE_ART) AS DESCRIPCION_PRODUCTO,
                TRIM(TRAILING FROM O.STR_OBS) AS COLOR,
                COALESCE(COMP.CADCOMP || ' ' || HI.CODIGO, '-') AS COMPOSICION,
                (SELECT FIRST 1 ACB2.DESCR
                FROM ACABTIPO ACB2
                WHERE ACB2.TIPO = AR.TIPOACAB
                AND ACB2.ESTAMPADO = 0) AS COMPOSICION2,
                CAST(P.CANT AS NUMERIC(18,2)) AS KG,
                P.UNI_VENTA AS UM,
                CAST(P.PREC AS NUMERIC(18,2)) AS PRECIO_BRUTO,
                P.CANT * P.PREC AS SUBTOTAL,
                CAST(P.TOTIMP4 AS NUMERIC(18,2)) AS IVA,
                CAST(( (P.CANT * P.PREC)  ) AS NUMERIC(18,2)) AS TOTAL
            FROM FACTF03 F
            INNER JOIN PAR_FACTF03   P    ON P.CVE_DOC  = F.CVE_DOC
            INNER JOIN CLIE03        C    ON C.CLAVE    = F.CVE_CLPV
            INNER JOIN INVE03        I    ON I.CVE_ART  = P.CVE_ART
            LEFT  JOIN ARTICULOS     AR   ON AR.CVE_ART = I.CVE_ART
            LEFT  JOIN TEJIDO        T    ON T.ID       = AR.TEJ
            LEFT  JOIN COMPOSICION   COMP ON COMP.ID    = AR.COMP
            LEFT  JOIN HILATURA      HI   ON HI.ID      = AR.HILAT
            LEFT  JOIN OBS_DOCF03    O    ON O.CVE_OBS  = P.CVE_OBS
            WHERE
                F.STATUS  = 'E'
                AND F.fecha_doc >= ?
                AND F.fecha_doc <= ?
                AND P.CANT > 0
                AND I.LIN_PROD IN ('HILOS', 'PTPR')
                AND NOT EXISTS (
                    SELECT 1
                    FROM FACTD03 FD
                    INNER JOIN PAR_FACTD03 PD ON PD.CVE_DOC = FD.CVE_DOC
                    WHERE FD.CVE_DOC  = F.DOC_SIG
                    AND FD.STATUS   = 'E'
                    AND PD.CVE_ART  = P.CVE_ART
                    AND PD.CANT     = P.CANT
                )
            ORDER BY F.FECHA_DOC, F.FOLIO, P.NUM_PAR
        ";

            $sqlNotasVenta = "
            SELECT DISTINCT
	    F.CAN_TOT,
                P.UNI_VENTA,
                F.IMPORTE,
                I.LIN_PROD AS LINEA_PRODUCTO
            FROM FACTV03 F
            INNER JOIN PAR_FACTV03 P ON F.CVE_DOC = P.CVE_DOC
            INNER JOIN INVE03      I ON I.CVE_ART = P.CVE_ART
            WHERE F.STATUS = 'E'
            AND F.FECHA_DOC >= ?
            AND F.FECHA_DOC <= ?
        ";

            $rows           = DB::connection('firebird')->select($sql, [$fechaInicio, $fechaFinExclusiva]);
            $rowsNotasVenta = DB::connection('firebird')->select($sqlNotasVenta, [$fechaInicio, $fechaFinExclusiva]);

            // ── Mapeo del detalle ─────────────────────────────────────────────────
            $detalle = array_map(function ($r) {
                return [
                    'fecha'                  => $r->FECHA                  ?? null,
                    'mes'                    => $r->MES                    ?? null,
                    'cliente'                => $r->CLIENTE                ?? null,
                    'factura'                => $r->FACTURA                ?? null,
                    'remision'               => $r->REMISION               ?? '-',
                    'pedido'                 => $r->PEDIDO                 ?? null,
                    'linea_producto'         => $r->LINEA_PRODUCTO         ?? null,  // ← AGREGADO
                    'modalidad'              => $r->MODALIDAD              ?? null,
                    'empresa'                => $r->EMPRESA                ?? null,
                    'clasificacion_producto' => $r->CLASIFICACION_PRODUCTO ?? null,
                    'descripcion_producto'   => $r->DESCRIPCION_PRODUCTO   ?? null,
                    'color'                  => $r->COLOR                  ?? null,
                    'composicion'            => $r->COMPOSICION            ?? null,
                    'composicion2'           => $r->COMPOSICION2           ?? null,
                    'cant'                   => (float) ($r->KG            ?? 0),
                    'um'                     => $r->UM                     ?? null,
                    'precio_bruto'           => (float) ($r->PRECIO_BRUTO  ?? 0),
                    'importe'                => (float) ($r->SUBTOTAL       ?? 0),
                    'impuestos'              => (float) ($r->IVA            ?? 0),
                    'total'                  => (float) ($r->TOTAL          ?? 0),
                ];
            }, $rows);

            // ── Totales generales ─────────────────────────────────────────────────
            $totalImporte   = array_sum(array_column($detalle, 'importe'));
            $totalImpuestos = array_sum(array_column($detalle, 'impuestos'));
            $totalGeneral   = array_sum(array_column($detalle, 'total'));
            $totalCant      = array_sum(array_column($detalle, 'cant'));
            $facturasUnicas = count(array_unique(array_column($detalle, 'factura')));

            // ── Totales por línea (PTPR / HILOS) ──────────────────────────────────
            $totalesPorLinea = [];
            foreach ($detalle as $item) {
                $linea = $item['linea_producto'] ?? 'SIN_LINEA';

                if (!isset($totalesPorLinea[$linea])) {
                    $totalesPorLinea[$linea] = [
                        'cant'      => 0.0,
                        'importe'   => 0.0,
                        'impuestos' => 0.0,
                        'total'     => 0.0,
                    ];
                }

                $totalesPorLinea[$linea]['cant']      += $item['cant'];
                $totalesPorLinea[$linea]['importe']   += $item['importe'];
                $totalesPorLinea[$linea]['impuestos'] += $item['impuestos'];
                $totalesPorLinea[$linea]['total']     += $item['total'];
            }

            // Redondear para evitar floating point feo
            foreach ($totalesPorLinea as $linea => $vals) {
                $totalesPorLinea[$linea]['cant']      = round($vals['cant'],      2);
                $totalesPorLinea[$linea]['importe']   = round($vals['importe'],   2);
                $totalesPorLinea[$linea]['impuestos'] = round($vals['impuestos'], 2);
                $totalesPorLinea[$linea]['total']     = round($vals['total'],     2);
            }

            // ── Notas de venta ────────────────────────────────────────────────────
            $totalNotasVenta = array_sum(array_map(fn($r) => (float) ($r->IMPORTE ?? 0), $rowsNotasVenta));

            $unidades       = [];   // totales por unidad de medida (sin cambio)
            $notasPorLinea  = [];   // ← NUEVO: totales por línea PTPR / HILOS

            foreach ($rowsNotasVenta as $r) {
                $um    = $r->UNI_VENTA      ?? 'SIN_UM';
                $linea = $r->LINEA_PRODUCTO ?? 'SIN_LINEA';
                $cant  = (float) ($r->CAN_TOT   ?? 0);
                $cant  = (float) ($r->CANT   ?? 0);
                $imp   = (float) ($r->IMPORTE ?? 0);

                // — por unidad de medida (ya existía) —
                if (!isset($unidades[$um])) {
                    $unidades[$um] = ['um' => $um, 'cant' => 0];
                }
                $unidades[$um]['cant'] += $cant;

                // — por línea de producto (NUEVO) —
                if (!isset($notasPorLinea[$linea])) {
                    $notasPorLinea[$linea] = [
                        'cant'  => 0.0,
                        'total' => 0.0,
                    ];
                }
                $notasPorLinea[$linea]['cant']  += $cant;
                $notasPorLinea[$linea]['total'] += $imp;
            }

            // Redondear
            foreach ($notasPorLinea as $linea => $vals) {
                $notasPorLinea[$linea]['cant']  = round($vals['cant'],  2);
                $notasPorLinea[$linea]['total'] = round($vals['total'], 2);
            }

            $unidades = array_values($unidades);

            return response()->json([
                'success' => true,
                'data' => [
                    'totales' => [
                        'facturas'  => $facturasUnicas,
                        'cant'      => round((float) $totalCant,      2),
                        'importe'   => round((float) $totalImporte,   2),
                        'impuestos' => round((float) $totalImpuestos, 2),
                        'total'     => round((float) $totalGeneral,   2),
                    ],
                    'por_linea'   => $totalesPorLinea,       // ← PTPR y HILOS desglosados
                    'notas_venta' => [
                        'registros' => count($rowsNotasVenta),
                        'total'     => $totalNotasVenta,
                        'unidades'  => $unidades,
                        'por_linea' => $notasPorLinea,
                    ],
                    'detalle' => $detalle,
                ],
                'filtros' => [
                    'fecha_inicio'        => $fechaInicio,
                    'fecha_fin'           => $fechaFin,
                    'fecha_fin_exclusiva' => $fechaFinExclusiva,
                    'total_registros'     => count($rows),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener FACTURADO',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * 🔥 Subtotales de FACTURADO agrupados por día
     */
    public function getFacturadoPorDia(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors'  => $validator->errors(),
                ], 400);
            }

            $fechaInicioISO    = substr($request->input('fecha_inicio'), 0, 10);
            $fechaFinISO       = substr($request->input('fecha_fin'), 0, 10);
            $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFinISO . ' +1 day'));

            $sql = "
            SELECT
                F.FECHA_DOC                             AS FECHA,
                COUNT(DISTINCT F.CVE_DOC)               AS FACTURAS,
                CAST(SUM(P.CANT) AS NUMERIC(18,2))      AS CANT,
                P.UNI_VENTA                             AS UM,
                CAST(SUM(P.CANT * P.PREC) AS NUMERIC(18,2))        AS IMPORTE,
                CAST(SUM(P.TOTIMP4) AS NUMERIC(18,2))              AS IMPUESTOS,
                I.LIN_PROD AS LINEA_PRODUCTO,
                CAST(SUM(P.CANT * P.PREC * 1.16) AS NUMERIC(18,2)) AS TOTAL
            FROM FACTF03 F
            INNER JOIN PAR_FACTF03   P    ON P.CVE_DOC  = F.CVE_DOC
            INNER JOIN CLIE03        C    ON C.CLAVE    = F.CVE_CLPV
            INNER JOIN INVE03        I    ON I.CVE_ART  = P.CVE_ART
            LEFT  JOIN ARTICULOS     AR   ON AR.CVE_ART = I.CVE_ART
            LEFT  JOIN TEJIDO        T    ON T.ID       = AR.TEJ
            LEFT  JOIN COMPOSICION   COMP ON COMP.ID    = AR.COMP
            LEFT  JOIN HILATURA      HI   ON HI.ID      = AR.HILAT
            LEFT  JOIN OBS_DOCF03    O    ON O.CVE_OBS  = P.CVE_OBS
            WHERE
                F.STATUS        = 'E'
                AND F.FECHA_DOC >= ?
                AND F.FECHA_DOC <=  ?
                AND P.CANT       > 0
                AND I.LIN_PROD IN ('HILOS', 'PTPR')
                AND NOT EXISTS (
                    SELECT 1
                    FROM FACTD03 FD
                    INNER JOIN PAR_FACTD03 PD ON PD.CVE_DOC = FD.CVE_DOC
                    WHERE FD.CVE_DOC  = F.DOC_SIG
                    AND FD.STATUS   = 'E'
                    AND PD.CVE_ART  = P.CVE_ART
                    AND PD.CANT     = P.CANT
                )
            GROUP BY F.FECHA_DOC, P.UNI_VENTA
            ORDER BY F.FECHA_DOC ASC
        ";

            // Notas de venta agrupadas por día
            $sqlNotasVenta = "
            SELECT
                CAST(F.FECHA_DOC AS DATE)      AS FECHA,
                COUNT(F.CVE_DOC)               AS REGISTROS,
                CAST(SUM(F.IMPORTE) AS NUMERIC(18,2)) AS TOTAL_NV
            FROM FACTV03 F
            WHERE F.STATUS = 'E'
              AND F.FECHA_DOC >= ?
              AND F.FECHA_DOC <= ?
            GROUP BY CAST(F.FECHA_DOC AS DATE)
            ORDER BY CAST(F.FECHA_DOC AS DATE) ASC
        ";

            $rows           = DB::connection('firebird')->select($sql, [$fechaInicioISO, $fechaFinExclusiva]);
            $rowsNotasVenta = DB::connection('firebird')->select($sqlNotasVenta, [$fechaInicioISO, $fechaFinExclusiva]);

            $subtotalesPorDia = array_map(fn($r) => [
                'fecha'     => $r->FECHA     ?? null,
                'facturas'  => (int)   ($r->FACTURAS  ?? 0),
                'cant'      => (float) ($r->CANT      ?? 0),
                'um'        => $r->UM        ?? null,
                'importe'   => (float) ($r->IMPORTE   ?? 0),
                'impuestos' => (float) ($r->IMPUESTOS ?? 0),
                'total'     => (float) ($r->TOTAL     ?? 0),
            ], $rows);

            // Indexar notas de venta por fecha para fácil acceso desde el front
            $notasVentaPorDia = [];
            foreach ($rowsNotasVenta as $r) {
                $fecha = substr($r->FECHA ?? '', 0, 10);
                $notasVentaPorDia[$fecha] = [
                    'registros' => (int)   ($r->REGISTROS ?? 0),
                    'total_nv'  => (float) ($r->TOTAL_NV  ?? 0),
                ];
            }


            return response()->json([
                'success' => true,
                'data'    => $subtotalesPorDia,       // ← sin cambios
                'notas_venta_por_dia' => $notasVentaPorDia, // ← nuevo
                'filtros' => [
                    'fecha_inicio'        => $fechaInicioISO,
                    'fecha_fin'           => $fechaFinISO,
                    'fecha_fin_exclusiva' => $fechaFinExclusiva,
                    'total_registros'     => count($rows),
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener facturado por día',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * 🔥 Obtener solo datos de ESTAMPADO con filtros de fecha.
     */
    public function getEstampado(Request $request)
    {
        try {
            // Validar parámetros de entrada
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'nullable|string',
                'fecha_fin' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Validar formato de fechas Firebird
            if ($fechaInicio && $fechaFin) {
                if (
                    ! $this->validarFormatoFechaFirebird($fechaInicio) ||
                    ! $this->validarFormatoFechaFirebird($fechaFin)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
                    ], 400);
                }
            }

            // Consulta a Firebird SOLO ESTAMPADO
            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
                ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
                ->select(
                    'd.DEPTO as departamento',
                    'p.PROCESO as proceso',
                    DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                    DB::raw('SUM("op"."PZASENT") as PIEZAS')
                )
                ->where('d.DEPTO', 'ESTAMPADO')  // 🔥 Filtrar por DEPARTAMENTO también
                ->where('p.PROCESO', 'ESTAMPADO'); // 🔥 Y por PROCESO

            // 🔥 IMPORTANTE: Aplicar exclusiones igual que index()
            $query->whereNotIn('d.DEPTO', $this->departamentosExcluidos);

            // 🔥 Filtrar por fechas si vienen (igual que index)
            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $reportes = $query
                ->groupBy('d.DEPTO', 'p.PROCESO')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reportes,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'total_registros' => $reportes->count(),
                    'departamentos_excluidos' => $this->departamentosExcluidos,
                    'proceso' => 'ESTAMPADO',
                ],
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes de producción (ESTAMPADO)',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Estampado por día
     */
    private function getEstampadoPorDiaData($fechaInicio, $fechaFin)
    {
        $sql = "
            SELECT
                CAST(op.FECHAENT AS DATE) AS FECHA,
                SUM(op.CANTENT) AS CANTIDAD,
                SUM(op.PZASENT) AS PIEZAS
            FROM ORDENESPROC op
            INNER JOIN PROCESOS p ON p.CODIGO = op.PROC
            INNER JOIN DEPTOS d ON d.CLAVE = op.DEPTO
            WHERE d.DEPTO = 'ESTAMPADO'
            AND p.PROCESO = 'ESTAMPADO'
            AND op.FECHAENT BETWEEN '{$fechaInicio}' AND '{$fechaFin}'
            GROUP BY CAST(op.FECHAENT AS DATE)
            ORDER BY CAST(op.FECHAENT AS DATE) ASC
        ";

        $rows = DB::connection('firebird')->select($sql);

        return array_map(fn($r) => [
            'FECHA'    => $r->FECHA,
            'CANTIDAD' => (float) $r->CANTIDAD,
            'PIEZAS'   => (int) $r->PIEZAS,
        ], $rows);
    }


    /**
     * 🔥 GET Estampado por día
     */
    public function getEstampadoPorDia(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
            }

            $fi = $request->input('fecha_inicio');
            $ff = $request->input('fecha_fin');

            if (!$this->validarFormatoFechaFirebird($fi) || !$this->validarFormatoFechaFirebird($ff)) {
                return response()->json(['success' => false, 'message' => 'Formato inválido. Use: dd.MM.yyyy HH:mm:ss'], 400);
            }

            $data = $this->getEstampadoPorDiaData($fi, $ff);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🔥 Obtener solo datos de TINTORERIA con filtros de fecha.
     */
    public function getTintoreria(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'nullable|string',
                'fecha_fin' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // 🔥 Validar formato igual que index()
            if ($fechaInicio && $fechaFin) {
                if (
                    ! $this->validarFormatoFechaFirebird($fechaInicio) ||
                    ! $this->validarFormatoFechaFirebird($fechaFin)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
                    ], 400);
                }
            }

            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
                ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
                ->select(
                    'd.DEPTO as departamento',
                    'p.PROCESO as proceso',
                    DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                    DB::raw('SUM("op"."PZASENT") as PIEZAS')
                )
                ->where('d.DEPTO', 'TINTORERIA')
                ->where('p.PROCESO', 'TEÑIDO'); // 🔥 Mantén este filtro si solo quieres TEÑIDO

            // 🔥 IMPORTANTE: Aplicar exclusiones igual que index()
            $query->whereNotIn('d.DEPTO', $this->departamentosExcluidos);

            // 🔥 Filtrar por fechas si vienen (igual que index)
            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $reportes = $query
                ->groupBy('d.DEPTO', 'p.PROCESO')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reportes,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'total_registros' => $reportes->count(),
                    'departamento' => 'TINTORERIA',
                    'proceso' => 'TEÑIDO',
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes de TINTORERIA',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Tintorería por día
     */
    private function getTintoreriaPorDiaData($fechaInicio, $fechaFin)
    {
        $sql = "
            SELECT
                CAST(op.FECHAENT AS DATE) AS FECHA,
                SUM(op.CANTENT) AS CANTIDAD,
                SUM(op.PZASENT) AS PIEZAS
            FROM ORDENESPROC op
            INNER JOIN PROCESOS p ON p.CODIGO = op.PROC
            INNER JOIN DEPTOS d ON d.CLAVE = op.DEPTO
            WHERE d.DEPTO = 'TINTORERIA'
            AND p.PROCESO = 'TEÑIDO'
            AND op.FECHAENT BETWEEN '{$fechaInicio}' AND '{$fechaFin}'
            GROUP BY CAST(op.FECHAENT AS DATE)
            ORDER BY CAST(op.FECHAENT AS DATE) ASC
        ";

        $rows = DB::connection('firebird')->select($sql);

        return array_map(fn($r) => [
            'FECHA'    => $r->FECHA,
            'CANTIDAD' => (float) $r->CANTIDAD,
            'PIEZAS'   => (int) $r->PIEZAS,
        ], $rows);
    }

    /**
     * 🔥 GET Tintorería por día
     */
    public function getTintoreriaPorDia(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
            }

            $fi = $request->input('fecha_inicio');
            $ff = $request->input('fecha_fin');

            if (!$this->validarFormatoFechaFirebird($fi) || !$this->validarFormatoFechaFirebird($ff)) {
                return response()->json(['success' => false, 'message' => 'Formato inválido. Use: dd.MM.yyyy HH:mm:ss'], 400);
            }

            $data = $this->getTintoreriaPorDiaData($fi, $ff);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * 🔥 Obtener solo datos de TEJIDO con filtros de fecha.
     */
    public function getTejido(Request $request)
    {
        try {
            // Validar parámetros de entrada
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'nullable|string',
                'fecha_fin' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors(),
                ], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Validar formato de fechas Firebird
            if ($fechaInicio && $fechaFin) {
                if (
                    ! $this->validarFormatoFechaFirebird($fechaInicio) ||
                    ! $this->validarFormatoFechaFirebird($fechaFin)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
                    ], 400);
                }
            }

            // Consulta a Firebird SOLO TEJIDO
            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
                ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
                ->select(
                    'd.DEPTO as departamento',
                    'p.PROCESO as proceso',
                    DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                    DB::raw('SUM("op"."PZASENT") as PIEZAS')
                )

                ->where('d.DEPTO', 'TEJIDO')     // 🔥 Filtrar por DEPARTAMENTO
                ->where('p.PROCESO', 'TEJIDO');  // 🔥 Filtrar por PROCESO

            // 🔥 IMPORTANTE: Aplicar exclusiones igual que index()
            $query->whereNotIn('d.DEPTO', $this->departamentosExcluidos);

            // 🔥 Filtrar por fechas si vienen
            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $reportes = $query
                ->groupBy('d.DEPTO', 'p.PROCESO')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reportes,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                    'total_registros' => $reportes->count(),
                    'departamentos_excluidos' => $this->departamentosExcluidos,
                    'departamento' => 'TEJIDO',
                    'proceso' => 'TEJIDO',
                ],
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes de producción (TEJIDO)',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Tejido por día
     */
    private function getTejidoPorDiaData($fechaInicio, $fechaFin)
    {
        $sql = "
            SELECT
                CAST(op.FECHAENT AS DATE) AS FECHA,
                SUM(op.CANTENT) AS CANTIDAD,
                SUM(op.PZASENT) AS PIEZAS
            FROM ORDENESPROC op
            INNER JOIN PROCESOS p ON p.CODIGO = op.PROC
            INNER JOIN DEPTOS d ON d.CLAVE = op.DEPTO
            WHERE d.DEPTO = 'TEJIDO'
            AND p.PROCESO = 'TEJIDO'
            AND op.FECHAENT BETWEEN '{$fechaInicio}' AND '{$fechaFin}'
            GROUP BY CAST(op.FECHAENT AS DATE)
            ORDER BY CAST(op.FECHAENT AS DATE) ASC
        ";

        $rows = DB::connection('firebird')->select($sql);

        return array_map(fn($r) => [
            'FECHA'    => $r->FECHA,
            'CANTIDAD' => (float) $r->CANTIDAD,
            'PIEZAS'   => (int) $r->PIEZAS,
        ], $rows);
    }

    /**
     * 🔥 GET Tejido por día
     */
    public function getTejidoPorDia(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
            }

            $fi = $request->input('fecha_inicio');
            $ff = $request->input('fecha_fin');

            if (!$this->validarFormatoFechaFirebird($fi) || !$this->validarFormatoFechaFirebird($ff)) {
                return response()->json(['success' => false, 'message' => 'Formato inválido. Use: dd.MM.yyyy HH:mm:ss'], 400);
            }

            $data = $this->getTejidoPorDiaData($fi, $ff);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }


    /**
     * 🔥 Obtener producción de TEJIDO por artículo (con filtros de fecha)
     */
    public function getProduccionTejido(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            $query = "
                SELECT
                    a.NOMBRE AS ARTICULO,

                    COUNT(*) AS PIEZAS,

                    SUM(
                        CASE
                            WHEN p.PESOTJ SIMILAR TO '[0-9]+([.,][0-9]+)?'
                            THEN CAST(p.PESOTJ AS DECIMAL(18,2))
                            ELSE 0
                        END
                    ) AS TOTAL_TJ

                FROM PSDTABPZASTJ p
                INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
            ";

            if ($fechaInicio && $fechaFin) {
                $query .= "
                WHERE p.FECHAYHORAPSD BETWEEN '$fechaInicio' AND '$fechaFin'
            ";
            }

            $query .= '
            GROUP BY a.NOMBRE
            ORDER BY a.NOMBRE
        ';

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener producción de tejido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Obtener revisado por artículo (con filtros de fecha)
     */
    public function getRevisadoTejido(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            $query = "
                SELECT
                    a.NOMBRE AS ARTICULO,

                    COUNT(
                        CASE
                            WHEN p.PESORV SIMILAR TO '[0-9]+([.,][0-9]+)?'
                            THEN 1
                            ELSE NULL
                        END
                    ) AS PIEZAS,

                    SUM(
                        CASE
                            WHEN p.PESORV SIMILAR TO '[0-9]+([.,][0-9]+)?'
                            THEN CAST(p.PESORV AS DECIMAL(18,2))
                            ELSE 0
                        END
                    ) AS TOTAL_RV

                FROM PSDTABPZASTJ p
                INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
            ";

            if ($fechaInicio && $fechaFin) {
                $fechaInicioTS = date('d.m.Y H:i:s', strtotime($fechaInicio));
                $fechaFinTS = date('d.m.Y H:i:s', strtotime($fechaFin . ' +1 day'));

                $query .= "
            WHERE (p.FECHAYHORAREV >= CAST('$fechaInicioTS' AS TIMESTAMP)
              AND p.FECHAYHORAREV < CAST('$fechaFinTS' AS TIMESTAMP) AND COALESCE(ISSALDO,0)=0)
            ";
            }

            $query .= '
        GROUP BY a.NOMBRE
        ORDER BY a.NOMBRE
        ';

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener revisado de tejido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Obtener por revisar por artículo (con filtros de fecha) + conteo total
     */
    public function getPorRevisarTejido(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Formatear fechas a TIMESTAMP Firebird (YYYY-MM-DD HH:MM:SS)
            $fechaInicioTS = $fechaInicio ? date('Y-m-d 00:00:00', strtotime($fechaInicio)) : null;
            $fechaFinTS = $fechaFin ? date('Y-m-d 23:59:59', strtotime($fechaFin)) : null;

            // Query por artículo
            $query = "
                SELECT
                    a.NOMBRE AS ARTICULO,
                    COUNT(*) AS PIEZAS,
                    SUM(
                        CASE
                            WHEN p.PESOTJ IS NOT NULL
                            THEN CAST(REPLACE(p.PESOTJ, ',', '.') AS DECIMAL(18,2))
                            ELSE 0
                        END
                    ) AS TOTAL_POR_REVISAR
                FROM PSDTABPZASTJ p
                INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
                WHERE COALESCE(p.ISREV,0) = 0
            ";

            if ($fechaInicioTS && $fechaFinTS) {
                $query .= "
                AND p.FECHAYHORAPSD >= CAST('$fechaInicioTS' AS TIMESTAMP)
                AND p.FECHAYHORAPSD <= CAST('$fechaFinTS' AS TIMESTAMP)
            ";
            } else {
                $query .= ' WHERE COALESCE(p.ISREV,0) = 0 ';
            }

            $query .= '
            GROUP BY a.NOMBRE
            ORDER BY a.NOMBRE
            ';

            $data = DB::connection('firebird')->select($query);

            // Query total de registros por fecha
            $totalQuery = '
            SELECT COUNT(*) AS TOTAL_REGISTROS
            FROM PSDTABPZASTJ p
            WHERE COALESCE(p.ISREV,0) = 0
            ';

            if ($fechaInicioTS && $fechaFinTS) {
                $totalQuery .= "
              AND p.FECHAYHORAPSD >= CAST('$fechaInicioTS' AS TIMESTAMP)
              AND p.FECHAYHORAPSD <= CAST('$fechaFinTS' AS TIMESTAMP)
            ";
            }

            $total = DB::connection('firebird')->select($totalQuery);

            return response()->json([
                'success' => true,
                'data' => $data,
                'total' => $total[0]->TOTAL_REGISTROS ?? 0,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener saldos por revisar de tejido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Obtener saldos por artículo (con filtros de fecha)
     */
    public function getSaldosTejido(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            $query = "
                SELECT
                    a.NOMBRE AS ARTICULO,
                    COUNT(
                        CASE
                            WHEN p.PESOSL SIMILAR TO '[0-9]+([.,][0-9]+)?'
                            THEN 1
                            ELSE NULL
                        END
                    ) AS PIEZAS,
                    SUM(
                        CASE
                            WHEN p.PESOSL SIMILAR TO '[0-9]+([.,][0-9]+)?'
                            THEN CAST(p.PESOSL AS DECIMAL(18,2))
                            ELSE 0
                        END
                    ) AS TOTAL_SALDO
                FROM PSDTABPZASTJ p
                INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
            ";

            if ($fechaInicio && $fechaFin) {
                $fechaInicioTS = date('Y-m-d 00:00:00', strtotime($fechaInicio));
                $fechaFinTS = date('Y-m-d 23:59:59', strtotime($fechaFin));

                $query .= "
                WHERE p.FECHAYHORAREV BETWEEN CAST('$fechaInicioTS' AS TIMESTAMP)
                                          AND CAST('$fechaFinTS' AS TIMESTAMP)
                  AND COALESCE(p.ISSALDO,0) = 1
            ";
            }

            $query .= '
            GROUP BY a.NOMBRE
            ORDER BY a.NOMBRE';

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener saldos de tejido revisado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Obtener producción por tipo de tejido, artículo Y FECHA
     */
    public function getEntregadoaEmbarques(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Formatear fechas a TIMESTAMP Firebird (dd.MM.yyyy HH:mm:ss)
            $fechaInicioTS = $fechaInicio ? date('d.m.Y 00:00:00', strtotime($fechaInicio)) : null;
            $fechaFinTS = $fechaFin ? date('d.m.Y 23:59:59', strtotime($fechaFin)) : null;

            $query = "
                SELECT
                    CASE P.TIPO
                        WHEN 51 THEN 'PRIMERA'
                        WHEN 52 THEN 'PREFERIDA'
                        WHEN 73 THEN 'ORILLAS'
                        WHEN 74 THEN 'RETAZO'
                        WHEN 77 THEN 'SEGUNDA'
                        WHEN 81 THEN 'MUESTRAS'
                        ELSE 'SIN CLASIFICAR'
                    END AS TIPO,
                    VA.ARTICULO,
                    CAST(P.FECHAYHORA AS DATE) AS FECHA,
                    SUM(P.PNETO) AS CANTIDAD
                FROM PSDTABPZAS P
                INNER JOIN PSDENC PE ON PE.CLAVE = P.CVE_ENC
                INNER JOIN V_ARTICULOS VA ON VA.ID = CAST(SUBSTRING(PE.CVE_ART FROM 4 FOR 7) AS NUMERIC)
            ";

            if ($fechaInicioTS && $fechaFinTS) {
                $query .= "
                WHERE P.FECHAYHORA >= '$fechaInicioTS'
                AND P.FECHAYHORA <= '$fechaFinTS'
                AND P.ESTATUS = 1
            ";
            } else {
                $query .= ' WHERE P.ESTATUS = 1 ';
            }

            $query .= '
            GROUP BY CAST(P.FECHAYHORA AS DATE), P.TIPO, VA.ARTICULO
            ORDER BY CAST(P.FECHAYHORA AS DATE) ASC, P.TIPO ASC, VA.ARTICULO ASC
        ';

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener producción por tipo de tejido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validar formato de fecha Firebird (dd.MM.yyyy HH:mm:ss)
     */
    private function validarFormatoFechaFirebird(string $fecha): bool
    {
        $pattern = '/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}:\d{2}$/';

        return preg_match($pattern, $fecha) === 1;
    }

    /**
     * Get production summary statistics
     */
    public function getSummary(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->select(
                    DB::raw('COUNT(DISTINCT op.DEPTO) as total_departamentos'),
                    DB::raw('COUNT(DISTINCT op.PROC) as total_procesos'),
                    DB::raw('SUM(op.CANTENT) as cantidad_total'),
                    DB::raw('COUNT(*) as total_ordenes')
                );

            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $summary = $query->first();

            return response()->json([
                'success' => true,
                'data' => $summary,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get production by department
     */
    public function getByDepartment(Request $request, $departmentId)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
                ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
                ->select(
                    'd.DEPTO as departamento',
                    'p.PROCESO as proceso',
                    DB::raw('CAST(CEILING(SUM(op.CANTENT)) AS DECIMAL(18,3)) as CANTIDAD')
                )
                ->where('op.DEPTO', '=', $departmentId);

            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $reportes = $query
                ->groupBy('d.DEPTO', 'p.PROCESO')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $reportes,
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes por departamento',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Obtener solo datos de ACABADO con filtros de fecha.
     */
    public function getAcabadoReal(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'nullable|string',
                'fecha_fin'    => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors'  => $validator->errors(),
                ], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin    = $request->input('fecha_fin');

            if ($fechaInicio && $fechaFin) {
                if (
                    ! $this->validarFormatoFechaFirebird($fechaInicio) ||
                    ! $this->validarFormatoFechaFirebird($fechaFin)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
                    ], 400);
                }
            }

            $query = DB::connection('firebird')
                ->table('ORDENESPROC as op')
                ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
                ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
                ->select(
                    'd.DEPTO as departamento',
                    'p.PROCESO as proceso',
                    DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                    DB::raw('SUM("op"."PZASENT") as PIEZAS')
                )
                ->where('d.DEPTO', 'CONTROL DE CALIDAD')
                ->where('p.PROCESO', 'CONTROL DE CALIDAD');

            // ✅ Aplicar excluidos pero sin tumbar CONTROL DE CALIDAD
            $excluidos = array_values(array_diff($this->departamentosExcluidos, ['CONTROL DE CALIDAD']));
            $query->whereNotIn('d.DEPTO', $excluidos);

            if ($fechaInicio && $fechaFin) {
                $query->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin]);
            }

            $reportes = $query
                ->groupBy('d.DEPTO', 'p.PROCESO')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $reportes,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin'    => $fechaFin,
                    'total_registros' => $reportes->count(),
                    'departamentos_excluidos' => $excluidos, // 👈 para debug real
                    'departamento' => 'CONTROL DE CALIDAD',
                    'proceso'      => 'CONTROL DE CALIDAD',
                ],
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes de CONTROL DE CALIDAD',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔥 Acabado (Control de Calidad) por día
     */
    private function getAcabadoPorDiaData($fechaInicio, $fechaFin)
    {
        $excluidos = array_values(array_diff($this->departamentosExcluidos, ['CONTROL DE CALIDAD']));
        $excluidosStr = implode("','", $excluidos);

        $sql = "
        SELECT
            CAST(op.FECHAENT AS DATE) AS FECHA,
            SUM(op.CANTENT) AS CANTIDAD,
            SUM(op.PZASENT) AS PIEZAS
        FROM ORDENESPROC op
        INNER JOIN PROCESOS p ON p.CODIGO = op.PROC
        INNER JOIN DEPTOS d ON d.CLAVE = op.DEPTO
        WHERE d.DEPTO = 'CONTROL DE CALIDAD'
          AND p.PROCESO = 'CONTROL DE CALIDAD'
          AND d.DEPTO NOT IN ('{$excluidosStr}')
          AND op.FECHAENT BETWEEN '{$fechaInicio}' AND '{$fechaFin}'
        GROUP BY CAST(op.FECHAENT AS DATE)
        ORDER BY CAST(op.FECHAENT AS DATE) ASC
            ";

        $rows = DB::connection('firebird')->select($sql);

        // ✅ Castear explícitamente para que no lleguen como string
        return array_map(fn($r) => [
            'FECHA'    => $r->FECHA,
            'CANTIDAD' => (float) $r->CANTIDAD,
            'PIEZAS'   => (int) $r->PIEZAS,
        ], $rows);
    }

    /**
     * 🔥 GET Acabado por día
     */
    public function getAcabadoPorDia(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 400);
            }

            $fi = $request->input('fecha_inicio');
            $ff = $request->input('fecha_fin');

            if (!$this->validarFormatoFechaFirebird($fi) || !$this->validarFormatoFechaFirebird($ff)) {
                return response()->json(['success' => false, 'message' => 'Formato inválido. Use: dd.MM.yyyy HH:mm:ss'], 400);
            }

            $data = $this->getAcabadoPorDiaData($fi, $ff);
            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET ALL REPORTS
     */
    // public function getAllReports(Request $request)
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'fecha_inicio' => 'required|string',
    //             'fecha_fin'    => 'required|string',
    //         ]);

    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Parámetros inválidos',
    //                 'errors'  => $validator->errors(),
    //             ], 400);
    //         }

    //         $fechaInicio = $request->input('fecha_inicio');
    //         $fechaFin = $request->input('fecha_fin');

    //         // Validar formato
    //         if (
    //             !$this->validarFormatoFechaFirebird($fechaInicio) ||
    //             !$this->validarFormatoFechaFirebird($fechaFin)
    //         ) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
    //             ], 400);
    //         }

    //         $key = "reportes:all:" . md5($fechaInicio . '|' . $fechaFin);

    //         $data = Cache::remember($key, now()->addSeconds(60), function () use ($fechaInicio, $fechaFin) {
    //             return [
    //                 'facturado'   => $this->getFacturadoData($fechaInicio, $fechaFin),
    //                 'embarques'   => $this->getEmbarquesData($fechaInicio, $fechaFin),
    //                 'tejido'      => $this->getTejidoResumenData($fechaInicio, $fechaFin),
    //                 'tintoreria'  => $this->getTintoreriaData($fechaInicio, $fechaFin),
    //                 'estampados'  => $this->getEstampadosData($fechaInicio, $fechaFin),
    //                 'acabado'     => $this->getAcabadoData($fechaInicio, $fechaFin),
    //                 'produccion'  => $this->getProduccionTejidoData($fechaInicio, $fechaFin),
    //                 'revisado'    => $this->getRevisadoTejidoData($fechaInicio, $fechaFin),
    //                 'porRevisar'  => $this->getPorRevisarTejidoData($fechaInicio, $fechaFin),
    //                 'saldos'      => $this->getSaldosTejidoData($fechaInicio, $fechaFin),
    //             ];
    //         });

    //         //disparamos evento
    //         // broadcast(new ReportesActualizados(
    //         //     'Reportes actualizados',
    //         //     ['total_registros' => count($data)]
    //         // ))->toOthers();

    //         return response()->json([
    //             'success' => true,
    //             'data' => $data,
    //             'filtros' => [
    //                 'fecha_inicio' => $fechaInicio,
    //                 'fecha_fin' => $fechaFin,
    //             ],
    //         ], 200);
    //     } catch (Throwable $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al obtener reportes consolidados',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function getAllReports(Request $request)
    {
        Log::info('getAllReports - INICIO');
        try {
            $validator = Validator::make($request->all(), [
                'fecha_inicio' => 'required|string',
                'fecha_fin'    => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Parámetros inválidos', 'errors' => $validator->errors()], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin    = $request->input('fecha_fin');

            if (!$this->validarFormatoFechaFirebird($fechaInicio) || !$this->validarFormatoFechaFirebird($fechaFin)) {
                return response()->json(['success' => false, 'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss'], 400);
            }

            $ttl = now()->addMinutes(5);
            $h   = md5($fechaInicio . '|' . $fechaFin);

            $data = [
                'facturado'  => Cache::remember("rpt:facturado:$h",  $ttl, fn() => $this->getFacturadoData($fechaInicio, $fechaFin)),
                // 'facturado'  => $this->getFacturadoData($fechaInicio, $fechaFin),
                'embarques'  => Cache::remember("rpt:embarques:$h",  $ttl, fn() => $this->getEmbarquesData($fechaInicio, $fechaFin)),
                'tejido'     => Cache::remember("rpt:tejido:$h",     $ttl, fn() => $this->getTejidoResumenData($fechaInicio, $fechaFin)),
                'tintoreria' => Cache::remember("rpt:tintoreria:$h", $ttl, fn() => $this->getTintoreriaData($fechaInicio, $fechaFin)),
                'estampados' => Cache::remember("rpt:estampados:$h", $ttl, fn() => $this->getEstampadosData($fechaInicio, $fechaFin)),
                'acabado'    => Cache::remember("rpt:acabado:$h",    $ttl, fn() => $this->getAcabadoData($fechaInicio, $fechaFin)),
                'produccion' => Cache::remember("rpt:produccion:$h", $ttl, fn() => $this->getProduccionTejidoData($fechaInicio, $fechaFin)),
                'revisado'   => Cache::remember("rpt:revisado:$h",   $ttl, fn() => $this->getRevisadoTejidoData($fechaInicio, $fechaFin)),
                'porRevisar' => Cache::remember("rpt:porrevisar:$h", $ttl, fn() => $this->getPorRevisarTejidoData($fechaInicio, $fechaFin)),
                'saldos'     => Cache::remember("rpt:saldos:$h",     $ttl, fn() => $this->getSaldosTejidoData($fechaInicio, $fechaFin)),
            ];

            return response()->json([
                'success' => true,
                'data'    => $data,
                'filtros' => ['fecha_inicio' => $fechaInicio, 'fecha_fin' => $fechaFin],
            ], 200);
        } catch (Throwable $e) {
            Log::error('getAllReports - ERROR: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al obtener reportes consolidados', 'error' => $e->getMessage()], 500);
        }
    }
    private function getEmbarquesData($fechaInicio, $fechaFin)
    {
        $query = "
        SELECT
            CASE P.TIPO
                WHEN 51 THEN 'PRIMERA'
                WHEN 52 THEN 'PREFERIDA'
                WHEN 73 THEN 'ORILLAS'
                WHEN 74 THEN 'RETAZO'
                WHEN 77 THEN 'SEGUNDA'
                WHEN 81 THEN 'MUESTRAS'
                ELSE 'SIN CLASIFICAR'
            END AS TIPO,
            VA.ARTICULO,
            CAST(P.FECHAYHORA AS DATE) AS FECHA,
            SUM(P.PNETO) AS CANTIDAD
        FROM PSDTABPZAS P
        INNER JOIN PSDENC PE ON PE.CLAVE = P.CVE_ENC
        INNER JOIN V_ARTICULOS VA ON VA.ID = CAST(SUBSTRING(PE.CVE_ART FROM 4 FOR 7) AS NUMERIC)
        WHERE P.FECHAYHORA >= '$fechaInicio'
          AND P.FECHAYHORA <= '$fechaFin'
          AND P.ESTATUS = 1
        GROUP BY CAST(P.FECHAYHORA AS DATE), P.TIPO, VA.ARTICULO
        ORDER BY CAST(P.FECHAYHORA AS DATE) ASC
        ";
        return DB::connection('firebird')->select($query);
    }

    private function getTejidoResumenData($fechaInicio, $fechaFin)
    {
        return DB::connection('firebird')
            ->table('ORDENESPROC as op')
            ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
            ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
            ->select(
                'd.DEPTO as departamento',
                'p.PROCESO as proceso',
                DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                DB::raw('SUM("op"."PZASENT") as PIEZAS')
            )
            ->where('d.DEPTO', 'TEJIDO')
            ->where('p.PROCESO', 'TEJIDO')
            ->whereNotIn('d.DEPTO', $this->departamentosExcluidos)
            ->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin])
            ->groupBy('d.DEPTO', 'p.PROCESO')
            ->get();
    }

    private function getTintoreriaData($fechaInicio, $fechaFin)
    {
        return DB::connection('firebird')
            ->table('ORDENESPROC as op')
            ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
            ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
            ->select(
                'd.DEPTO as departamento',
                'p.PROCESO as proceso',
                DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                DB::raw('SUM("op"."PZASENT") as PIEZAS')
            )
            ->where('d.DEPTO', 'TINTORERIA')
            ->where('p.PROCESO', 'TEÑIDO')
            ->whereNotIn('d.DEPTO', $this->departamentosExcluidos)
            ->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin])
            ->groupBy('d.DEPTO', 'p.PROCESO')
            ->get();
    }

    private function getEstampadosData($fechaInicio, $fechaFin)
    {
        return DB::connection('firebird')
            ->table('ORDENESPROC as op')
            ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
            ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
            ->select(
                'd.DEPTO as departamento',
                'p.PROCESO as proceso',
                DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                DB::raw('SUM("op"."PZASENT") as PIEZAS')
            )
            ->where('d.DEPTO', 'ESTAMPADO')
            ->where('p.PROCESO', 'ESTAMPADO')
            ->whereNotIn('d.DEPTO', $this->departamentosExcluidos)
            ->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin])
            ->groupBy('d.DEPTO', 'p.PROCESO')
            ->get();
    }

    private function getAcabadoData($fechaInicio, $fechaFin)
    {
        $excluidos = array_values(array_diff($this->departamentosExcluidos, ['CONTROL DE CALIDAD']));

        return DB::connection('firebird')
            ->table('ORDENESPROC as op')
            ->join('PROCESOS as p', 'p.CODIGO', '=', 'op.PROC')
            ->join('DEPTOS as d', 'd.CLAVE', '=', 'op.DEPTO')
            ->select(
                'd.DEPTO as departamento',
                'p.PROCESO as proceso',
                DB::raw('CAST(CEILING(SUM("op"."CANTENT")) AS DECIMAL(18,3)) as CANTIDAD'),
                DB::raw('SUM("op"."PZASENT") as PIEZAS')
            )
            ->where('d.DEPTO', 'CONTROL DE CALIDAD')
            ->where('p.PROCESO', 'CONTROL DE CALIDAD')
            ->whereNotIn('d.DEPTO', $excluidos)
            ->whereBetween('op.FECHAENT', [$fechaInicio, $fechaFin])
            ->groupBy('d.DEPTO', 'p.PROCESO')
            ->get();
    }

    private function getProduccionTejidoData($fechaInicio, $fechaFin)
    {
        $query = "
        SELECT
            a.NOMBRE AS ARTICULO,
            COUNT(*) AS PIEZAS,
            SUM(
                CASE WHEN p.PESOTJ SIMILAR TO '[0-9]+([.,][0-9]+)?'
                THEN CAST(p.PESOTJ AS DECIMAL(18,2))
                ELSE 0 END
            ) AS TOTAL_TJ
        FROM PSDTABPZASTJ p
        INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
        WHERE p.FECHAYHORAPSD BETWEEN '$fechaInicio' AND '$fechaFin'
        GROUP BY a.NOMBRE
        ORDER BY a.NOMBRE
        ";
        return DB::connection('firebird')->select($query);
    }

    private function getRevisadoTejidoData($fechaInicio, $fechaFin)
    {
        $fechaInicioTS = date('d.m.Y H:i:s', strtotime($fechaInicio));
        $fechaFinTS = date('d.m.Y H:i:s', strtotime($fechaFin . ' +1 day'));

        $query = "
        SELECT
            a.NOMBRE AS ARTICULO,
            COUNT(CASE WHEN p.PESORV SIMILAR TO '[0-9]+([.,][0-9]+)?' THEN 1 ELSE NULL END) AS PIEZAS,
            SUM(
                CASE WHEN p.PESORV SIMILAR TO '[0-9]+([.,][0-9]+)?'
                THEN CAST(p.PESORV AS DECIMAL(18,2))
                ELSE 0 END
            ) AS TOTAL_RV
        FROM PSDTABPZASTJ p
        INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
        WHERE (p.FECHAYHORAREV >= CAST('$fechaInicioTS' AS TIMESTAMP)
          AND p.FECHAYHORAREV < CAST('$fechaFinTS' AS TIMESTAMP)
          AND COALESCE(ISSALDO,0)=0)
        GROUP BY a.NOMBRE
        ORDER BY a.NOMBRE
        ";
        return DB::connection('firebird')->select($query);
    }

    private function getPorRevisarTejidoData($fechaInicio, $fechaFin)
    {
        $fechaInicioTS = date('Y-m-d 00:00:00', strtotime($fechaInicio));
        $fechaFinTS = date('Y-m-d 23:59:59', strtotime($fechaFin));

        $query = "
        SELECT
            a.NOMBRE AS ARTICULO,
            COUNT(*) AS PIEZAS,
            SUM(
                CASE WHEN p.PESOTJ IS NOT NULL
                THEN CAST(REPLACE(p.PESOTJ, ',', '.') AS DECIMAL(18,2))
                ELSE 0 END
            ) AS TOTAL_POR_REVISAR
        FROM PSDTABPZASTJ p
        INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
        WHERE COALESCE(p.ISREV,0) = 0
          AND p.FECHAYHORAPSD >= CAST('$fechaInicioTS' AS TIMESTAMP)
          AND p.FECHAYHORAPSD <= CAST('$fechaFinTS' AS TIMESTAMP)
        GROUP BY a.NOMBRE
        ORDER BY a.NOMBRE
        ";
        return DB::connection('firebird')->select($query);
    }

    private function getSaldosTejidoData($fechaInicio, $fechaFin)
    {
        $fechaInicioTS = date('Y-m-d 00:00:00', strtotime($fechaInicio));
        $fechaFinTS = date('Y-m-d 23:59:59', strtotime($fechaFin));

        $query = "
        SELECT
            a.NOMBRE AS ARTICULO,
            COUNT(CASE WHEN p.PESOSL SIMILAR TO '[0-9]+([.,][0-9]+)?' THEN 1 ELSE NULL END) AS PIEZAS,
            SUM(
                CASE WHEN p.PESOSL SIMILAR TO '[0-9]+([.,][0-9]+)?'
                THEN CAST(p.PESOSL AS DECIMAL(18,2))
                ELSE 0 END
            ) AS TOTAL_SALDO
        FROM PSDTABPZASTJ p
        INNER JOIN ARTICULOS a ON a.ID = p.CVE_ART
        WHERE p.FECHAYHORAREV BETWEEN CAST('$fechaInicioTS' AS TIMESTAMP)
                                  AND CAST('$fechaFinTS' AS TIMESTAMP)
          AND COALESCE(p.ISSALDO,0) = 1
        GROUP BY a.NOMBRE
        ORDER BY a.NOMBRE
        ";
        return DB::connection('firebird')->select($query);
    }

    public function toggleOcultar(Request $request, $z200_id)
    {
        Log::info('toggleOcultar - INICIO', [
            'z200_id' => $z200_id,
            'auth_user' => Auth::user()
        ]);

        try {

            $user = Auth::user();

            if (!$user) {
                Log::warning('toggleOcultar - Usuario no autenticado');
                return response()->json(['message' => 'No autenticado'], 401);
            }

            Log::info('toggleOcultar - Usuario autenticado', [
                'user_id' => $user->ID
            ]);

            $identity = UserFirebirdIdentity::where('firebird_user_clave', $user->ID)->first();

            if (!$identity) {
                Log::warning('toggleOcultar - Identidad no encontrada', [
                    'firebird_user_clave' => $user->ID
                ]);

                return response()->json(['message' => 'Identidad no encontrada'], 404);
            }

            Log::info('toggleOcultar - Identidad encontrada', [
                'identity_id' => $identity->id
            ]);

            $registro = Ocultar::firstOrCreate([
                'user_id' => $identity->id,
                'z200_id' => $z200_id,
            ]);

            Log::info('toggleOcultar - Registro obtenido/creado', [
                'registro_id' => $registro->id,
                'oculto_actual' => $registro->oculto
            ]);

            $registro->oculto = !$registro->oculto;
            $registro->save();

            Log::info('toggleOcultar - Estado cambiado', [
                'nuevo_oculto' => $registro->oculto
            ]);

            return response()->json([
                'success' => true,
                'oculto'  => $registro->oculto,
            ]);
        } catch (\Exception $e) {

            Log::error('toggleOcultar - ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error interno'
            ], 500);
        }
    }

    public function getEstadoOculto(Request $request, $z200_id)
    {
        Log::info('getEstadoOculto - INICIO', [
            'z200_id' => $z200_id,
            'auth_user' => Auth::user()
        ]);

        try {

            $user = Auth::user();

            if (!$user) {
                Log::warning('getEstadoOculto - Usuario no autenticado');
                return response()->json(['success' => true, 'oculto' => false]);
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', $user->ID)->first();

            if (!$identity) {
                Log::warning('getEstadoOculto - Identidad no encontrada', [
                    'firebird_user_clave' => $user->ID
                ]);

                return response()->json(['success' => true, 'oculto' => false]);
            }

            Log::info('getEstadoOculto - Identidad encontrada', [
                'identity_id' => $identity->id
            ]);

            $registro = Ocultar::where('user_id', $identity->id)
                ->where('z200_id', $z200_id)
                ->first();

            Log::info('getEstadoOculto - Registro consultado', [
                'existe_registro' => $registro ? true : false,
                'oculto' => $registro ? $registro->oculto : false
            ]);

            return response()->json([
                'success' => true,
                'oculto' => $registro ? (bool) $registro->oculto : false,
            ]);
        } catch (\Exception $e) {

            Log::error('getEstadoOculto - ERROR', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error interno'
            ], 500);
        }
    }
}