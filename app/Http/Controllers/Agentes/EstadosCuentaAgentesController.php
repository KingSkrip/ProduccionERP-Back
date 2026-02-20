<?php

namespace App\Http\Controllers\Agentes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;

class EstadosCuentaAgentesController extends Controller
{
    protected function fb()
    {
        return DB::connection('firebird');
    }

    protected function queryBase()
    {
        return "
        SELECT * FROM (
            SELECT
                c.CLAVE,
                c.NOMBRE,
                c.RFC,
                c.STATUS,
                c.CVE_VEND,
                cm.NO_FACTURA AS DOCUMENTO,
                cm.FECHA_APLI,
                cm.FECHA_VENC,
                ROUND(cm.IMPORTE, 2) AS CARGOS,
                ROUND(COALESCE(SUM(cd.IMPMON_EXT), 0), 2) AS ABONOS,
                ROUND(cm.IMPORTE - COALESCE(SUM(cd.IMPMON_EXT), 0), 2) AS SALDOS,
                ROUND(c.SALDO, 2) AS TOTAL_SALDO
            FROM CLIE03 c
            INNER JOIN CUEN_M03 cm ON c.CLAVE = cm.CVE_CLIE
            LEFT JOIN CUEN_DET03 cd
                ON cm.CVE_CLIE = cd.CVE_CLIE
                AND cm.NO_FACTURA = cd.NO_FACTURA
            WHERE TRIM(UPPER(c.NOMBRE)) NOT IN (
                'COMERCIALIZADORA SION COMEX SAS',
                'Y TSHIRT GROUP'
            )
            AND cm.NO_FACTURA IS NOT NULL
            AND TRIM(c.CVE_VEND) = TRIM(?)
            GROUP BY
                c.CLAVE, c.NOMBRE, c.RFC, c.STATUS, c.CVE_VEND,
                cm.NO_FACTURA, cm.FECHA_APLI, cm.FECHA_VENC,
                cm.IMPORTE, c.SALDO
            HAVING ROUND(cm.IMPORTE - COALESCE(SUM(cd.IMPMON_EXT), 0), 2) > 0
        ) sub
        WHERE 1=1
    ";
    }

    protected function getAgenteClave()
    {
        Log::info('🔍 Entrando a getAgenteClave');

        $user = Auth::user();

        if (!$user) {
            Log::warning('⛔ Usuario no autenticado');
            abort(401, 'No autenticado');
        }

        Log::info('👤 Usuario autenticado', [
            'user_id' => $user->id ?? null,
            'firebird_ID' => $user->ID ?? null,
            'email' => $user->email ?? null,
        ]);

        $identityQuery = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->where('firebird_user_clave', $user->ID)
            ->where('firebird_vend_tabla', 'VEND03')
            ->whereNotNull('firebird_vend_clave');

        Log::info('🧠 Query preparada', [
            'firebird_user_clave' => $user->ID,
            'firebird_vend_tabla' => 'VEND03',
        ]);

        $identity = $identityQuery->first();

        if (!$identity) {
            Log::warning('⛔ No se encontró identidad vinculada', [
                'firebird_user_clave' => $user->ID,
            ]);
            abort(403, 'No es cliente CLIE');
        }

        Log::info('✅ Identidad encontrada', [
            'firebird_vend_clave' => $identity->firebird_vend_clave,
            'registro_completo' => $identity,
        ]);

        return $identity->firebird_vend_clave;
    }


    /* =======================================================
        📄 INDEX - Lista de movimientos del cliente
    ======================================================= */
    public function index(Request $request)
    {
        try {
            $cveVend = $this->getAgenteClave();

            $query = $this->queryBase();
            $query .= " ORDER BY FECHA_APLI DESC";

            $resultados = $this->fb()->select($query, [$cveVend]);

            $estadosCuenta = collect($resultados)->map(function ($item) {
                return [
                    'clave'             => trim($item->CLAVE),
                    'nombre'            => trim($item->NOMBRE ?? ''),
                    'rfc'               => trim($item->RFC ?? ''),
                    'status'            => $item->STATUS ?? '',
                    'documento'         => trim($item->DOCUMENTO),
                    'fecha_aplicacion'  => $item->FECHA_APLI ? Carbon::parse($item->FECHA_APLI)->format('Y-m-d') : null,
                    'fecha_vencimiento' => $item->FECHA_VENC ? Carbon::parse($item->FECHA_VENC)->format('Y-m-d') : null,
                    'cargos'            => (float) $item->CARGOS,
                    'abonos'            => (float) $item->ABONOS,
                    'saldo'             => (float) $item->SALDOS,
                    'total_saldo'       => (float) $item->TOTAL_SALDO,
                ];
            })->values();

            return response()->json([
                'success'      => true,
                'data'         => $estadosCuenta,
                'total'        => $estadosCuenta->count(),
                'total_cargos' => round($estadosCuenta->sum('cargos'), 2),
                'total_abonos' => round($estadosCuenta->sum('abonos'), 2),
                'total_saldos' => round($estadosCuenta->sum('saldo'), 2),
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_INDEX_ESTADOS_CUENTA', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estados de cuenta',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* =======================================================
        📊 RESUMEN - Totales del cliente
    ======================================================= */
    public function resumen()
    {
        try {
            $cveVend = $this->getAgenteClave();

            // Trae el primer cliente del vendedor para datos generales
            $queryCliente = "
            SELECT FIRST 1
                CLAVE, NOMBRE, RFC, STATUS,
                ROUND(COALESCE(SALDO, 0), 2) AS SALDO
            FROM CLIE03
            WHERE TRIM(CVE_VEND) = TRIM('$cveVend')
        ";

            $cliente = $this->fb()->selectOne($queryCliente);

            if (!$cliente) {
                return response()->json([
                    'success'       => false,
                    'message'       => 'No se encontraron clientes para este vendedor',
                    'clave_buscada' => $cveVend
                ], 404);
            }

            $query = $this->queryBase();
            $resultados = $this->fb()->select($query, [$cveVend]);
            $datos      = collect($resultados);

            $totalCargos = $datos->sum('CARGOS');
            $totalAbonos = $datos->sum('ABONOS');

            // Saldo total sumado de todos los clientes del vendedor
            $saldoTotal = $this->fb()->selectOne(
                "SELECT ROUND(COALESCE(SUM(SALDO), 0), 2) AS SALDO_TOTAL FROM CLIE03 WHERE TRIM(CVE_VEND) = TRIM('$cveVend')"
            )->SALDO_TOTAL ?? 0;

            $hoy      = Carbon::now();
            $vencidos = $datos->filter(function ($item) use ($hoy) {
                if (!$item->FECHA_VENC || $item->SALDOS <= 0) return false;
                try {
                    return Carbon::parse($item->FECHA_VENC)->lt($hoy);
                } catch (\Exception $e) {
                    return false;
                }
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'vendedor' => [
                        'cve_vend' => $cveVend,
                    ],
                    'totales' => [
                        'cargos'      => round($totalCargos, 2),
                        'abonos'      => round($totalAbonos, 2),
                        'saldo_total' => (float) $saldoTotal,
                    ],
                    'documentos' => [
                        'total'         => $datos->count(),
                        'vencidos'      => $vencidos->count(),
                        'monto_vencido' => round($vencidos->sum('SALDOS'), 2),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_RESUMEN_ESTADOS_CUENTA', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* =======================================================
        📅 POR AÑO - Movimientos filtrados por año
    ======================================================= */
    public function porAnio($anio)
    {
        try {
            $cveVend = $this->getAgenteClave();

            $query  = $this->queryBase();
            $query .= " AND EXTRACT(YEAR FROM FECHA_APLI) = ?";
            $query .= " ORDER BY FECHA_APLI DESC";
            $resultados = $this->fb()->select($query, [$cveVend, $anio]);

            $estadosCuenta = collect($resultados)->map(function ($item) {
                return [
                    'clave'             => trim($item->CLAVE),
                    'nombre'            => trim($item->NOMBRE ?? ''),
                    'rfc'               => trim($item->RFC ?? ''),
                    'status'            => $item->STATUS ?? '',
                    'documento'         => trim($item->DOCUMENTO),
                    'fecha_aplicacion'  => $item->FECHA_APLI ? Carbon::parse($item->FECHA_APLI)->format('Y-m-d') : null,
                    'fecha_vencimiento' => $item->FECHA_VENC ? Carbon::parse($item->FECHA_VENC)->format('Y-m-d') : null,
                    'cargos'            => (float) $item->CARGOS,
                    'abonos'            => (float) $item->ABONOS,
                    'saldo'             => (float) $item->SALDOS,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'anio'    => (int) $anio,
                'data'    => $estadosCuenta,
                'total'   => $estadosCuenta->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_POR_ANIO_ESTADOS_CUENTA', [
                'message' => $e->getMessage(),
                'anio'    => $anio,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estados de cuenta por año',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* =======================================================
        🔍 SHOW - Detalle de un documento específico
    ======================================================= */
    public function show($noFactura)
    {
        try {
            $cveVend = $this->getAgenteClave();

            $query  = $this->queryBase();
            $query .= " AND TRIM(DOCUMENTO) = TRIM(?)";

            $resultado = $this->fb()->selectOne($query, [$cveVend, $noFactura]);

            if (!$resultado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'clave'             => trim($resultado->CLAVE),
                    'nombre'            => trim($resultado->NOMBRE ?? ''),
                    'rfc'               => trim($resultado->RFC ?? ''),
                    'status'            => $resultado->STATUS ?? '',
                    'documento'         => trim($resultado->DOCUMENTO),
                    'fecha_aplicacion'  => $resultado->FECHA_APLI ? Carbon::parse($resultado->FECHA_APLI)->format('Y-m-d') : null,
                    'fecha_vencimiento' => $resultado->FECHA_VENC ? Carbon::parse($resultado->FECHA_VENC)->format('Y-m-d') : null,
                    'cargos'            => (float) $resultado->CARGOS,
                    'abonos'            => (float) $resultado->ABONOS,
                    'saldo'             => (float) $resultado->SALDOS,
                    'total_saldo'       => (float) $resultado->TOTAL_SALDO,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_SHOW_ESTADO_CUENTA', [
                'message'   => $e->getMessage(),
                'documento' => $noFactura,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener detalle',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* =======================================================
        📄 PDF - Descargar PDF individual
    ======================================================= */
    public function descargarPDF($noFactura)
    {
        try {
            $cveVend = $this->getAgenteClave();

            $query  = $this->queryBase();
            $query .= " AND TRIM(DOCUMENTO) = TRIM(?)";

            $resultado = $this->fb()->selectOne($query, [$cveVend, $noFactura]);

            if (!$resultado) {
                return response()->json(['success' => false, 'message' => 'Documento no encontrado'], 404);
            }

            $data = [
                'documento'        => $resultado,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s')
            ];

            $pdf = PDF::loadView('pdfs.estado-cuenta', $data);
            return $pdf->download("estado-cuenta-{$noFactura}.pdf");
        } catch (\Exception $e) {
            Log::error('ERROR_DESCARGAR_PDF', ['message' => $e->getMessage(), 'documento' => $noFactura]);
            return response()->json(['success' => false, 'message' => 'Error al generar PDF', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📦 DESCARGAR MÚLTIPLES PDFs
    ======================================================= */
    public function descargarMultiples(Request $request)
    {
        try {
            $request->validate([
                'documentos'   => 'required|array|min:1',
                'documentos.*' => 'required|string'
            ]);

            $cveVend    = $this->getAgenteClave();
            $documentos = $request->documentos;

            $placeholders = implode(',', array_fill(0, count($documentos), '?'));

            $query  = $this->queryBase();
            $query .= " AND TRIM(DOCUMENTO) IN ({$placeholders})";
            $query .= " ORDER BY FECHA_APLI DESC";

            $params = array_merge([$cveVend], $documentos);

            $resultados = $this->fb()->select($query, $params);

            if (empty($resultados)) {
                return response()->json(['success' => false, 'message' => 'No se encontraron documentos'], 404);
            }

            $data = [
                'documentos'       => $resultados,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
                'cliente'          => trim($resultados[0]->NOMBRE ?? '')
            ];

            $pdf = PDF::loadView('pdfs.estados-cuenta-multiples', $data);
            return $pdf->download("estados-cuenta-" . date('YmdHis') . ".pdf");
        } catch (\Exception $e) {
            Log::error('ERROR_DESCARGAR_MULTIPLES', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al generar PDF múltiple', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📧 ENVIAR EMAIL
    ======================================================= */
    public function enviarEmail(Request $request, $noFactura)
    {
        try {
            $request->validate(['email' => 'required|email']);

            $cveVend = $this->getAgenteClave();

            $query  = $this->queryBase();
            $query .= " AND TRIM(DOCUMENTO) = TRIM(?)";

            $resultado = $this->fb()->selectOne($query, [$cveVend, $noFactura]);

            if (!$resultado) {
                return response()->json(['success' => false, 'message' => 'Documento no encontrado'], 404);
            }

            $data = [
                'documento'        => $resultado,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s')
            ];

            $pdf = PDF::loadView('pdfs.estado-cuenta', $data);

            Mail::send('emails.estado-cuenta', $data, function ($message) use ($request, $pdf, $noFactura) {
                $message->to($request->email)
                    ->subject('Estado de Cuenta - ' . $noFactura)
                    ->attachData($pdf->output(), "estado-cuenta-{$noFactura}.pdf");
            });

            return response()->json(['success' => true, 'message' => 'Email enviado correctamente']);
        } catch (\Exception $e) {
            Log::error('ERROR_ENVIAR_EMAIL', ['message' => $e->getMessage(), 'documento' => $noFactura]);
            return response()->json(['success' => false, 'message' => 'Error al enviar email', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        🏗 GENERAR (NO APLICA)
    ======================================================= */
    public function generar(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada - solo lectura desde Firebird'
        ], 501);
    }

    /* =======================================================
        🔄 ACTUALIZAR ESTADO
    ======================================================= */
    public function actualizarEstado(Request $request, $noFactura)
    {
        try {
            $request->validate([
                'status' => 'required|string'
            ]);

            $clie = $this->getAgenteClave();

            $query  = $this->queryBase();
            $query .= " AND TRIM(DOCUMENTO) = TRIM(?)";

            $resultado = $this->fb()->selectOne($query, [$clie, $noFactura]);

            if (!$resultado) {
                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado'
                ], 404);
            }

            $this->fb()->update(
                "UPDATE CLIE03 SET STATUS = ? WHERE TRIM(CVE_VEND) = TRIM(?)",
                [$request->status, $clie]
            );

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente'
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_ACTUALIZAR_ESTADO', [
                'message'   => $e->getMessage(),
                'documento' => $noFactura,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar estado',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /* =======================================================
        🗑 DELETE (NO PERMITIDO)
    ======================================================= */
    public function destroy($noFactura)
    {
        return response()->json([
            'success' => false,
            'message' => 'No se permite eliminar estados de cuenta'
        ], 403);
    }
}