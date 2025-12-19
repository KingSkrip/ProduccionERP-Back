<?php

namespace App\Http\Controllers\SuperAdmin\ReportesProduccion;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReportesProduccionController extends Controller
{
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
                    'errors' => $validator->errors()
                ], 400);
            }

            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Validar formato de fechas Firebird (dd.MM.yyyy HH:mm:ss)
            if ($fechaInicio && $fechaFin) {
                if (
                    !$this->validarFormatoFechaFirebird($fechaInicio) ||
                    !$this->validarFormatoFechaFirebird($fechaFin)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Formato de fecha inválido. Use: dd.MM.yyyy HH:mm:ss'
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
                    DB::raw('SUM("op"."CANTENT") as CANTIDAD')
                );

            // ✅ Excluir TEJIDO
            $query->where(function ($q) {
                $q->where('p.PROCESO', '<>', 'TEJIDO')
                    ->where('d.DEPTO', '<>', 'TEJIDO');
            });

            // ✅ SOLO filtra si vienen fechas
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
                    'total_registros' => $reportes->count()
                ]
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes de producción',
                'error' => $e->getMessage()
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
            $fechaFin    = $request->input('fecha_fin');

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

            $query .= "
            GROUP BY a.NOMBRE
            ORDER BY a.NOMBRE
        ";

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener producción de tejido',
                'error' => $e->getMessage()
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
            $fechaFin    = $request->input('fecha_fin');

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
                $fechaFinTS    = date('d.m.Y H:i:s', strtotime($fechaFin . ' +1 day'));

                $query .= "
            WHERE (p.FECHAYHORAREV >= CAST('$fechaInicioTS' AS TIMESTAMP)
              AND p.FECHAYHORAREV < CAST('$fechaFinTS' AS TIMESTAMP) AND COALESCE(ISSALDO,0)=0)
            ";
            }

            $query .= "
        GROUP BY a.NOMBRE
        ORDER BY a.NOMBRE
        ";

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener revisado de tejido',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * 🔥 Obtener por revisar por artículo (con filtros de fecha) + conteo total
     */
    /**
     * 🔥 Obtener por revisar por artículo (con filtros de fecha) + conteo total
     */
    public function getPorRevisarTejido(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin    = $request->input('fecha_fin');

            // Formatear fechas a TIMESTAMP Firebird (YYYY-MM-DD HH:MM:SS)
            $fechaInicioTS = $fechaInicio ? date('Y-m-d 00:00:00', strtotime($fechaInicio)) : null;
            $fechaFinTS    = $fechaFin ? date('Y-m-d 23:59:59', strtotime($fechaFin)) : null;

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
                $query .= " WHERE COALESCE(p.ISREV,0) = 0 ";
            }

            $query .= "
            GROUP BY a.NOMBRE
            ORDER BY a.NOMBRE
            ";

            $data = DB::connection('firebird')->select($query);

            // Query total de registros por fecha
            $totalQuery = "
            SELECT COUNT(*) AS TOTAL_REGISTROS
            FROM PSDTABPZASTJ p
            WHERE COALESCE(p.ISREV,0) = 0
            ";

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
                    'fecha_fin' => $fechaFin
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener saldos por revisar de tejido',
                'error' => $e->getMessage()
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
            $fechaFin    = $request->input('fecha_fin');

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
                $fechaFinTS    = date('Y-m-d 23:59:59', strtotime($fechaFin));

                $query .= "
                WHERE p.FECHAYHORAREV BETWEEN CAST('$fechaInicioTS' AS TIMESTAMP)
                                          AND CAST('$fechaFinTS' AS TIMESTAMP)
                  AND COALESCE(p.ISSALDO,0) = 1
            ";
            }

            $query .= "
            GROUP BY a.NOMBRE
            ORDER BY a.NOMBRE
        ";

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener saldos de tejido revisado',
                'error' => $e->getMessage()
            ], 500);
        }
    }





    /**
     * 🔥 Obtener producción por tipo de tejido y artículo
     */
    public function getEntregadoaEmbarques(Request $request)
    {
        try {
            $fechaInicio = $request->input('fecha_inicio');
            $fechaFin = $request->input('fecha_fin');

            // Formatear fechas a TIMESTAMP Firebird (dd.MM.yyyy HH:mm:ss)
            $fechaInicioTS = $fechaInicio ? date('d.m.Y 00:00:00', strtotime($fechaInicio)) : null;
            $fechaFinTS    = $fechaFin ? date('d.m.Y 23:59:59', strtotime($fechaFin)) : null;

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
                $query .= " WHERE P.ESTATUS = 1 ";
            }

            $query .= "
            GROUP BY P.TIPO, VA.ARTICULO
            ORDER BY VA.ARTICULO ASC, P.TIPO ASC
        ";

            $data = DB::connection('firebird')->select($query);

            return response()->json([
                'success' => true,
                'data' => $data,
                'filtros' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener producción por tipo de tejido',
                'error' => $e->getMessage()
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
                'data' => $summary
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen',
                'error' => $e->getMessage()
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
                    DB::raw('SUM(op.CANTENT) as CANTIDAD')
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
                'data' => $reportes
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar reportes por departamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
