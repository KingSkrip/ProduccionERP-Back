<?php

namespace App\Http\Controllers\SuperAdmin\ReportesProduccion;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
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

    /**
     * 🔥 FACTURADO DETALLE: Desglose por factura (con cliente, UM y totales)
     */
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

            // Extraer solo YYYY-MM-DD
            $fechaInicio = substr($request->input('fecha_inicio'), 0, 10);
            $fechaFin    = substr($request->input('fecha_fin'), 0, 10);

            /**
             * Para que el fin sea inclusivo SIN depender si FECHA_DOC es DATE o TIMESTAMP:
             * usamos:
             *   FECHA_DOC >= inicio
             *   FECHA_DOC < (fin + 1 día)
             */
            $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFin . ' +1 day'));

            $sql = "
            SELECT
                C.nombre            AS CLIENTE,
                F.cve_doc           AS FACTURA,
                SUM(P.CANT)         AS CANT,
                P.UNI_VENTA         AS UM,
                F.FECHA_DOC         AS FECHA,


                CASE
                    WHEN COALESCE(F.tipcamb, 1) = 1 THEN COALESCE(F.can_tot, 0)
                    ELSE COALESCE(F.can_tot, 0) * COALESCE(F.tipcamb, 1)
                END AS IMPORTE,

                CASE
                    WHEN COALESCE(F.tipcamb, 1) = 1 THEN COALESCE(F.imp_tot4, 0)
                    ELSE COALESCE(F.imp_tot4, 0) * COALESCE(F.tipcamb, 1)
                END AS IMPUESTOS,

                CASE
                    WHEN COALESCE(F.tipcamb, 1) = 1 THEN (COALESCE(F.can_tot, 0) + COALESCE(F.imp_tot4, 0))
                    ELSE (COALESCE(F.can_tot, 0) * COALESCE(F.tipcamb, 1)) + (COALESCE(F.imp_tot4, 0) * COALESCE(F.tipcamb, 1))
                END AS TOTAL

            FROM PAR_FACTF03 P
            LEFT JOIN FACTF03 F ON F.cve_doc = P.cve_doc
            LEFT JOIN CLIE03 C  ON C.clave  = F.cve_clpv

            WHERE F.status IN ('E','O')
              AND F.FECHA_DOC >= ?
              AND F.FECHA_DOC < ?

            GROUP BY
                C.nombre,
                F.cve_doc,
                P.UNI_VENTA,
                F.can_tot,
                F.imp_tot4,
                F.tipcamb,
                F.FECHA_DOC

            ORDER BY F.cve_doc
        ";

            $rows = DB::connection('firebird')->select($sql, [$fechaInicio, $fechaFinExclusiva]);

            // Formateo detalle
            $detalle = array_map(function ($r) {
                return [
                    'cliente'   => $r->CLIENTE,
                    'factura'   => $r->FACTURA,
                    'fecha'     => $r->FECHA, // ✅ IMPORTANTE
                    'cant'      => (float) ($r->CANT ?? 0),
                    'um'        => $r->UM,
                    'importe'   => (float) ($r->IMPORTE ?? 0),
                    'impuestos' => (float) ($r->IMPUESTOS ?? 0),
                    'total'     => (float) ($r->TOTAL ?? 0),
                ];
            }, $rows);


            /**
             * Totales monetarios SIN duplicar por UM:
             * sumamos 1 vez por FACTURA.
             */
            $facturas = [];
            foreach ($rows as $r) {
                $fac = $r->FACTURA;
                if (!isset($facturas[$fac])) {
                    $facturas[$fac] = [
                        'importe'   => (float) ($r->IMPORTE ?? 0),
                        'impuestos' => (float) ($r->IMPUESTOS ?? 0),
                        'total'     => (float) ($r->TOTAL ?? 0),
                    ];
                }
            }

            $totalImporte   = array_sum(array_column($facturas, 'importe'));
            $totalImpuestos = array_sum(array_column($facturas, 'impuestos'));
            $totalGeneral   = array_sum(array_column($facturas, 'total'));

            // Cantidad total (esta sí la sumo de todas las filas porque está por UM)
            $totalCant = array_reduce($rows, function ($carry, $item) {
                return $carry + (float) ($item->CANT ?? 0);
            }, 0);

            return response()->json([
                'success' => true,
                'data' => [
                    'totales' => [
                        'facturas'  => count($facturas),
                        'cant'      => (float) $totalCant,
                        'importe'   => (float) $totalImporte,
                        'impuestos' => (float) $totalImpuestos,
                        'total'     => (float) $totalGeneral,
                    ],
                    'detalle' => $detalle,
                ],
                'filtros' => [
                    'fecha_inicio'       => $fechaInicio,
                    'fecha_fin'          => $fechaFin,
                    'fecha_fin_exclusiva' => $fechaFinExclusiva,
                    'total_registros'    => count($rows),
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



    /***
     * 
     */


    /**
     * 🔥 NUEVO: Obtener TODOS los reportes en una sola petición
     */
    public function getAllReports(Request $request)
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

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Validar formato
            if (
                !$this->validarFormatoFechaFirebird($fechaInicio) ||
                !$this->validarFormatoFechaFirebird($fechaFin)
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss',
                ], 400);
            }

            // 🔥 Ejecutar TODAS las consultas en paralelo
            $data = [
                'facturado' => $this->getFacturadoData($fechaInicio, $fechaFin),
                'embarques' => $this->getEmbarquesData($fechaInicio, $fechaFin),
                'tejido' => $this->getTejidoResumenData($fechaInicio, $fechaFin),
                'tintoreria' => $this->getTintoreriaData($fechaInicio, $fechaFin),
                'estampados' => $this->getEstampadosData($fechaInicio, $fechaFin),
                'acabado' => $this->getAcabadoData($fechaInicio, $fechaFin),
                'produccion' => $this->getProduccionTejidoData($fechaInicio, $fechaFin),
                'revisado' => $this->getRevisadoTejidoData($fechaInicio, $fechaFin),
                'porRevisar' => $this->getPorRevisarTejidoData($fechaInicio, $fechaFin),
                'saldos' => $this->getSaldosTejidoData($fechaInicio, $fechaFin),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
            ], 200);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener reportes consolidados',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // 🔥 Métodos auxiliares para cada consulta
    private function getFacturadoData($fechaInicio, $fechaFin)
    {
        $fechaInicioISO = substr($fechaInicio, 6, 4) . '-' . substr($fechaInicio, 3, 2) . '-' . substr($fechaInicio, 0, 2);
        $fechaFinISO = substr($fechaFin, 6, 4) . '-' . substr($fechaFin, 3, 2) . '-' . substr($fechaFin, 0, 2);
        $fechaFinExclusiva = date('Y-m-d', strtotime($fechaFinISO . ' +1 day'));

        $sql = "
        SELECT
            C.nombre AS CLIENTE,
            F.cve_doc AS FACTURA,
            SUM(P.CANT) AS CANT,
            P.UNI_VENTA AS UM,
            F.FECHA_DOC AS FECHA,
            CASE WHEN COALESCE(F.tipcamb, 1) = 1 THEN COALESCE(F.can_tot, 0)
                 ELSE COALESCE(F.can_tot, 0) * COALESCE(F.tipcamb, 1) END AS IMPORTE,
            CASE WHEN COALESCE(F.tipcamb, 1) = 1 THEN COALESCE(F.imp_tot4, 0)
                 ELSE COALESCE(F.imp_tot4, 0) * COALESCE(F.tipcamb, 1) END AS IMPUESTOS,
            CASE WHEN COALESCE(F.tipcamb, 1) = 1 THEN (COALESCE(F.can_tot, 0) + COALESCE(F.imp_tot4, 0))
                 ELSE (COALESCE(F.can_tot, 0) * COALESCE(F.tipcamb, 1)) + (COALESCE(F.imp_tot4, 0) * COALESCE(F.tipcamb, 1)) END AS TOTAL
        FROM PAR_FACTF03 P
        LEFT JOIN FACTF03 F ON F.cve_doc = P.cve_doc
        LEFT JOIN CLIE03 C ON C.clave = F.cve_clpv
        WHERE F.status IN ('E','O')
          AND F.FECHA_DOC >= ?
          AND F.FECHA_DOC < ?
        GROUP BY C.nombre, F.cve_doc, P.UNI_VENTA, F.can_tot, F.imp_tot4, F.tipcamb, F.FECHA_DOC
        ORDER BY F.cve_doc
    ";

        $rows = DB::connection('firebird')->select($sql, [$fechaInicioISO, $fechaFinExclusiva]);

        $detalle = array_map(function ($r) {
            return [
                'cliente' => $r->CLIENTE,
                'factura' => $r->FACTURA,
                'fecha' => $r->FECHA,
                'cant' => (float) ($r->CANT ?? 0),
                'um' => $r->UM,
                'importe' => (float) ($r->IMPORTE ?? 0),
                'impuestos' => (float) ($r->IMPUESTOS ?? 0),
                'total' => (float) ($r->TOTAL ?? 0),
            ];
        }, $rows);

        $facturas = [];
        foreach ($rows as $r) {
            $fac = $r->FACTURA;
            if (!isset($facturas[$fac])) {
                $facturas[$fac] = [
                    'importe' => (float) ($r->IMPORTE ?? 0),
                    'impuestos' => (float) ($r->IMPUESTOS ?? 0),
                    'total' => (float) ($r->TOTAL ?? 0),
                ];
            }
        }

        $totalImporte = array_sum(array_column($facturas, 'importe'));
        $totalImpuestos = array_sum(array_column($facturas, 'impuestos'));
        $totalGeneral = array_sum(array_column($facturas, 'total'));
        $totalCant = array_reduce($rows, fn($carry, $item) => $carry + (float) ($item->CANT ?? 0), 0);

        return [
            'totales' => [
                'facturas' => count($facturas),
                'cant' => (float) $totalCant,
                'importe' => (float) $totalImporte,
                'impuestos' => (float) $totalImpuestos,
                'total' => (float) $totalGeneral,
            ],
            'detalle' => $detalle,
        ];
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
}