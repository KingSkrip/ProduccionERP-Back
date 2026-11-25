<?php

namespace App\Http\Controllers\Agentes;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EstadosCuentaAgentesController extends Controller
{
    protected function fb()
    {
        return DB::connection('firebird');
    }

    /* =======================================================
        🧪 HELPERS DE DEBUG (solo logs, no alteran lógica)
    ======================================================= */

    /**
     * Loguea el SQL final, los params y detecta desajuste entre
     * cantidad de "?" y cantidad de parámetros (error típico de binding).
     */
    protected function logQuery(string $tag, string $query, array $params): void
    {
        $placeholders = substr_count($query, '?');
        $paramsCount = count($params);
        $ok = $placeholders === $paramsCount;

        $context = [
            'placeholders' => $placeholders,
            'params_count' => $paramsCount,
            'bindings_ok' => $ok,
            'params' => $params,
            'sql' => trim(preg_replace('/\s+/', ' ', $query)),
        ];

        if ($ok) {
            Log::info("🧪 {$tag} SQL", $context);
        } else {
            Log::error("💥 {$tag} DESAJUSTE DE BINDINGS (placeholders != params)", $context);
        }
    }

    /**
     * Loguea cuántos registros regresó una consulta y cuánto tardó.
     */
    protected function logResultado(string $tag, $resultados, float $inicio): void
    {
        $count = is_countable($resultados) ? count($resultados) : ($resultados ? 1 : 0);

        Log::info("📦 {$tag} RESULTADO", [
            'registros' => $count,
            'ms' => round((microtime(true) - $inicio) * 1000, 2),
        ]);
    }

    /**
     * Log de error completo con archivo, línea, usuario y contexto.
     */
    protected function logError(string $tag, \Throwable $e, array $context = []): void
    {
        Log::error($tag, array_merge([
            'message' => $e->getMessage(),
            'class' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'user_id' => Auth::user()->ID ?? null,
            'trace' => $e->getTraceAsString(),
        ], $context));
    }

    protected function queryBase(?string $cveVend = null): string
    {
        $filtroVendedor = $cveVend ? 'AND TRIM(c.CVE_VEND) = TRIM(?)' : '';

        // 🔥 Clientes a excluir cuando tenga acceso total (subrol 9 o 10)
        $clientesExcluidos = "
        'SFA CAPITAL',
        'ALLIANCE INTERACTIVE TECHNOLOGIES',
        'CREACIONES LAIZA',
        'TEXTILES HECLA',
        'SABU SHAKRUKA CUENTA REM',
        'TEXTILES EL TRIUNFO',
        'INFANTILES DINAMITA',
        'SABU SALVADOR SHAKRUKA ROMANO',
        'ISAAC ZONANA (INSUMOS)',
        'ALTA FIBRA TECA',
        'ZURIZEN'
    ";

        $filtroClientesEspeciales = ! $cveVend
            ? "AND TRIM(UPPER(c.NOMBRE)) NOT IN ($clientesExcluidos)"
            : '';

        Log::debug('🧱 queryBase armado', [
            'cveVend' => $cveVend,
            'filtro_vendedor' => $filtroVendedor !== '',
            'filtro_clientes_especiales' => $filtroClientesEspeciales !== '',
        ]);

        return '
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
        FROM '.tb('CLIE').' c
        INNER JOIN '.tb('CUEN_M').' cm ON c.CLAVE = cm.CVE_CLIE
        LEFT JOIN '.tb('CUEN_DET')." cd
            ON cm.CVE_CLIE = cd.CVE_CLIE
            AND cm.NO_FACTURA = cd.NO_FACTURA
        WHERE TRIM(UPPER(c.NOMBRE)) NOT IN (
            'COMERCIALIZADORA SION COMEX SAS',
            'Y TSHIRT GROUP'
        )
        $filtroClientesEspeciales
        AND cm.NO_FACTURA IS NOT NULL
        {$filtroVendedor}
        GROUP BY
            c.CLAVE, c.NOMBRE, c.RFC, c.STATUS, c.CVE_VEND,
            cm.NO_FACTURA, cm.FECHA_APLI, cm.FECHA_VENC,
            cm.IMPORTE, c.SALDO
        HAVING ROUND(cm.IMPORTE - COALESCE(SUM(cd.IMPMON_EXT), 0), 2) > 0
    ) sub
    WHERE 1=1
    ";
    }

    protected function tieneAccesoTotal(): bool
    {
        $user = Auth::user();

        if (! $user) {
            Log::warning('⛔ tieneAccesoTotal: usuario no autenticado');

            return false;
        }

        // Buscar la identity del usuario
        $identity = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->where('firebird_user_clave', $user->ID)
            ->first();

        if (! $identity) {
            Log::warning('⛔ tieneAccesoTotal: no hay identity para el usuario', [
                'firebird_user_clave' => $user->ID,
            ]);

            return false;
        }

        // Leer los subrol_id desde model_has_roles
        $subPermissions = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('firebird_identity_id', $identity->id)
            ->pluck('subrol_id')
            ->filter() // quita nulos
            ->toArray();

        Log::info('🔐 SUB_PERMISSIONS_CHECK', [
            'user_id' => $user->ID,
            'identity_id' => $identity->id,
            'sub_permissions' => $subPermissions,
            'tiene_acceso' => in_array(9, $subPermissions) || in_array(10, $subPermissions),
        ]);

        return in_array(9, $subPermissions) || in_array(10, $subPermissions);
    }

    protected function getAgenteClave()
    {
        Log::info('🔍 Entrando a getAgenteClave');

        $user = Auth::user();

        if (! $user) {
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
            ->where('firebird_vend_tabla', tb('VEND'))
            ->whereNotNull('firebird_vend_clave');

        Log::info('🧠 Query preparada', [
            'firebird_user_clave' => $user->ID,
            'firebird_vend_tabla' => tb('VEND'),
        ]);

        $identity = $identityQuery->first();

        if (! $identity) {
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
        $inicio = microtime(true);
        Log::info('▶️ INDEX inicio', ['ip' => $request->ip(), 'query_string' => $request->query()]);

        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $cveVend = $accesoTotal ? null : $this->getAgenteClave();
            $params = $accesoTotal ? [] : [$cveVend];

            Log::info('🔑 INDEX acceso', ['acceso_total' => $accesoTotal, 'cveVend' => $cveVend]);

            $query = $this->queryBase($cveVend);
            $query .= ' ORDER BY FECHA_APLI DESC';

            $this->logQuery('INDEX', $query, $params);

            // $resultados = $this->fb()->select($query, [$cveVend]);

            $resultados = $this->fb()->select($query, $params);

            $this->logResultado('INDEX', $resultados, $inicio);

            $estadosCuenta = collect($resultados)->map(function ($item) {
                return [
                    'clave' => trim($item->CLAVE),
                    'nombre' => trim($item->NOMBRE ?? ''),
                    'rfc' => trim($item->RFC ?? ''),
                    'status' => $item->STATUS ?? '',
                    'documento' => trim($item->DOCUMENTO),
                    'fecha_aplicacion' => $item->FECHA_APLI ? Carbon::parse($item->FECHA_APLI)->format('Y-m-d') : null,
                    'fecha_vencimiento' => $item->FECHA_VENC ? Carbon::parse($item->FECHA_VENC)->format('Y-m-d') : null,
                    'cargos' => (float) $item->CARGOS,
                    'abonos' => (float) $item->ABONOS,
                    'saldo' => (float) $item->SALDOS,
                    'total_saldo' => (float) $item->TOTAL_SALDO,
                ];
            })->values();

            Log::info('✅ INDEX fin', [
                'total' => $estadosCuenta->count(),
                'total_cargos' => round($estadosCuenta->sum('cargos'), 2),
                'total_abonos' => round($estadosCuenta->sum('abonos'), 2),
                'total_saldos' => round($estadosCuenta->sum('saldo'), 2),
                'ms' => round((microtime(true) - $inicio) * 1000, 2),
            ]);

            return response()->json([
                'success' => true,
                'data' => $estadosCuenta,
                'total' => $estadosCuenta->count(),
                'total_cargos' => round($estadosCuenta->sum('cargos'), 2),
                'total_abonos' => round($estadosCuenta->sum('abonos'), 2),
                'total_saldos' => round($estadosCuenta->sum('saldo'), 2),
            ]);
        } catch (\Exception $e) {
            $this->logError('ERROR_INDEX_ESTADOS_CUENTA', $e);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estados de cuenta',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =======================================================
        📊 RESUMEN - Totales del cliente
    ======================================================= */
    public function resumen()
    {
        $inicio = microtime(true);
        Log::info('▶️ RESUMEN inicio');

        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $cveVend = $accesoTotal ? null : $this->getAgenteClave();
            $params = $accesoTotal ? [] : [$cveVend];

            Log::info('🔑 RESUMEN acceso', ['acceso_total' => $accesoTotal, 'cveVend' => $cveVend]);

            // ✅ Solo buscar cliente específico si NO tiene acceso total
            if (! $accesoTotal) {
                $queryCliente = '
                SELECT FIRST 1
                    CLAVE, NOMBRE, RFC, STATUS,
                    ROUND(COALESCE(SALDO, 0), 2) AS SALDO
                FROM '.tb('CLIE').'
                WHERE TRIM(CVE_VEND) = TRIM(?)
            ';

                $this->logQuery('RESUMEN_CLIENTE', $queryCliente, [$cveVend]);

                $cliente = $this->fb()->selectOne($queryCliente, [$cveVend]);

                Log::info('👥 RESUMEN cliente encontrado', [
                    'encontrado' => (bool) $cliente,
                    'cliente' => $cliente,
                ]);

                if (! $cliente) {
                    Log::warning('⚠️ RESUMEN: sin clientes para el vendedor', ['clave_buscada' => $cveVend]);

                    return response()->json([
                        'success' => false,
                        'message' => 'No se encontraron clientes para este vendedor',
                        'clave_buscada' => $cveVend,
                    ], 404);
                }
            }

            $query = $this->queryBase($cveVend);

            $this->logQuery('RESUMEN_QUERYBASE', $query, $params);

            $resultados = $this->fb()->select($query, $params);

            $this->logResultado('RESUMEN_QUERYBASE', $resultados, $inicio);

            $datos = collect($resultados);

            $totalCargos = $datos->sum('CARGOS');
            $totalAbonos = $datos->sum('ABONOS');

            // ✅ Saldo total según acceso
            $saldoQuery = $accesoTotal
                ? 'SELECT ROUND(COALESCE(SUM(SALDO), 0), 2) AS SALDO_TOTAL FROM '.tb('CLIE').''
                : 'SELECT ROUND(COALESCE(SUM(SALDO), 0), 2) AS SALDO_TOTAL FROM '.tb('CLIE').' WHERE TRIM(CVE_VEND) = TRIM(?)';

            $this->logQuery('RESUMEN_SALDO', $saldoQuery, $accesoTotal ? [] : [$cveVend]);

            $saldoTotal = $accesoTotal
                ? $this->fb()->selectOne($saldoQuery)->SALDO_TOTAL ?? 0
                : $this->fb()->selectOne($saldoQuery, [$cveVend])->SALDO_TOTAL ?? 0;

            Log::info('💰 RESUMEN saldo_total', ['saldo_total' => $saldoTotal]);

            $hoy = Carbon::now();
            $vencidos = $datos->filter(function ($item) use ($hoy) {
                if (! $item->FECHA_VENC || $item->SALDOS <= 0) {
                    return false;
                }
                try {
                    return Carbon::parse($item->FECHA_VENC)->lt($hoy);
                } catch (\Exception $e) {
                    Log::warning('⚠️ RESUMEN fecha de vencimiento inválida', [
                        'documento' => $item->DOCUMENTO ?? null,
                        'fecha_venc' => $item->FECHA_VENC,
                        'message' => $e->getMessage(),
                    ]);

                    return false;
                }
            });

            Log::info('✅ RESUMEN fin', [
                'total_cargos' => round($totalCargos, 2),
                'total_abonos' => round($totalAbonos, 2),
                'documentos' => $datos->count(),
                'vencidos' => $vencidos->count(),
                'monto_vencido' => round($vencidos->sum('SALDOS'), 2),
                'ms' => round((microtime(true) - $inicio) * 1000, 2),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'vendedor' => [
                        'cve_vend' => $cveVend ?? 'TODOS',
                    ],
                    'totales' => [
                        'cargos' => round($totalCargos, 2),
                        'abonos' => round($totalAbonos, 2),
                        'saldo_total' => (float) $saldoTotal,
                    ],
                    'documentos' => [
                        'total' => $datos->count(),
                        'vencidos' => $vencidos->count(),
                        'monto_vencido' => round($vencidos->sum('SALDOS'), 2),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            $this->logError('ERROR_RESUMEN_ESTADOS_CUENTA', $e);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener resumen',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =======================================================
        📅 POR AÑO - Movimientos filtrados por año
    ======================================================= */
    public function porAnio($anio)
    {
        $inicio = microtime(true);
        Log::info('▶️ POR_ANIO inicio', ['anio' => $anio]);

        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $cveVend = $accesoTotal ? null : $this->getAgenteClave();
            $params = $accesoTotal ? [$anio] : [$cveVend, $anio];

            Log::info('🔑 POR_ANIO acceso', ['acceso_total' => $accesoTotal, 'cveVend' => $cveVend]);

            $query = $this->queryBase($cveVend);
            $query .= ' AND EXTRACT(YEAR FROM FECHA_APLI) = ?';
            $query .= ' ORDER BY FECHA_APLI DESC';

            $this->logQuery('POR_ANIO', $query, $params);

            $resultados = $this->fb()->select($query, $params);

            $this->logResultado('POR_ANIO', $resultados, $inicio);

            $estadosCuenta = collect($resultados)->map(function ($item) {
                return [
                    'clave' => trim($item->CLAVE),
                    'nombre' => trim($item->NOMBRE ?? ''),
                    'rfc' => trim($item->RFC ?? ''),
                    'status' => $item->STATUS ?? '',
                    'documento' => trim($item->DOCUMENTO),
                    'fecha_aplicacion' => $item->FECHA_APLI ? Carbon::parse($item->FECHA_APLI)->format('Y-m-d') : null,
                    'fecha_vencimiento' => $item->FECHA_VENC ? Carbon::parse($item->FECHA_VENC)->format('Y-m-d') : null,
                    'cargos' => (float) $item->CARGOS,
                    'abonos' => (float) $item->ABONOS,
                    'saldo' => (float) $item->SALDOS,
                ];
            })->values();

            Log::info('✅ POR_ANIO fin', [
                'anio' => (int) $anio,
                'total' => $estadosCuenta->count(),
                'ms' => round((microtime(true) - $inicio) * 1000, 2),
            ]);

            return response()->json([
                'success' => true,
                'anio' => (int) $anio,
                'data' => $estadosCuenta,
                'total' => $estadosCuenta->count(),
            ]);
        } catch (\Exception $e) {
            $this->logError('ERROR_POR_ANIO_ESTADOS_CUENTA', $e, ['anio' => $anio]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estados de cuenta por año',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =======================================================
        🔍 SHOW - Detalle de un documento específico
    ======================================================= */
    public function show($noFactura)
    {
        $inicio = microtime(true);
        Log::info('▶️ SHOW inicio', ['documento' => $noFactura]);

        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $cveVend = $accesoTotal ? null : $this->getAgenteClave();
            $params = $accesoTotal ? [$noFactura] : [$cveVend, $noFactura];

            Log::info('🔑 SHOW acceso', ['acceso_total' => $accesoTotal, 'cveVend' => $cveVend]);

            $query = $this->queryBase($cveVend);
            $query .= ' AND TRIM(DOCUMENTO) = TRIM(?)';

            $this->logQuery('SHOW', $query, $params);

            $resultado = $this->fb()->selectOne($query, $params);

            $this->logResultado('SHOW', $resultado, $inicio);

            if (! $resultado) {
                Log::warning('⚠️ SHOW documento no encontrado', ['documento' => $noFactura, 'cveVend' => $cveVend]);

                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'clave' => trim($resultado->CLAVE),
                    'nombre' => trim($resultado->NOMBRE ?? ''),
                    'rfc' => trim($resultado->RFC ?? ''),
                    'status' => $resultado->STATUS ?? '',
                    'documento' => trim($resultado->DOCUMENTO),
                    'fecha_aplicacion' => $resultado->FECHA_APLI ? Carbon::parse($resultado->FECHA_APLI)->format('Y-m-d') : null,
                    'fecha_vencimiento' => $resultado->FECHA_VENC ? Carbon::parse($resultado->FECHA_VENC)->format('Y-m-d') : null,
                    'cargos' => (float) $resultado->CARGOS,
                    'abonos' => (float) $resultado->ABONOS,
                    'saldo' => (float) $resultado->SALDOS,
                    'total_saldo' => (float) $resultado->TOTAL_SALDO,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logError('ERROR_SHOW_ESTADO_CUENTA', $e, ['documento' => $noFactura]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener detalle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =======================================================
        📄 PDF - Descargar PDF individual
    ======================================================= */
    public function descargarPDF($noFactura)
    {
        $inicio = microtime(true);
        Log::info('▶️ DESCARGAR_PDF inicio', ['documento' => $noFactura]);

        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $cveVend = $accesoTotal ? null : $this->getAgenteClave();

            Log::info('🔑 DESCARGAR_PDF acceso', ['acceso_total' => $accesoTotal, 'cveVend' => $cveVend]);

            $query = $this->queryBase($cveVend); // ✅ pasar $cveVend
            $query .= ' AND TRIM(DOCUMENTO) = TRIM(?)';

            $params = $accesoTotal ? [$noFactura] : [$cveVend, $noFactura];

            $this->logQuery('DESCARGAR_PDF', $query, $params);

            $resultado = $this->fb()->selectOne($query, $params);

            $this->logResultado('DESCARGAR_PDF', $resultado, $inicio);

            if (! $resultado) {
                Log::warning('⚠️ DESCARGAR_PDF documento no encontrado', ['documento' => $noFactura, 'cveVend' => $cveVend]);

                return response()->json(['success' => false, 'message' => 'Documento no encontrado'], 404);
            }

            $data = [
                'documento' => $resultado,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
            ];

            Log::info('🖨️ DESCARGAR_PDF generando vista pdfs.estado-cuenta');

            $pdf = PDF::loadView('pdfs.estado-cuenta', $data);

            Log::info('✅ DESCARGAR_PDF PDF listo', ['ms' => round((microtime(true) - $inicio) * 1000, 2)]);

            return $pdf->download("estado-cuenta-{$noFactura}.pdf");
        } catch (\Exception $e) {
            $this->logError('ERROR_DESCARGAR_PDF', $e, ['documento' => $noFactura]);

            return response()->json(['success' => false, 'message' => 'Error al generar PDF', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📦 DESCARGAR MÚLTIPLES PDFs
    ======================================================= */
    public function descargarMultiples(Request $request)
    {
        $inicio = microtime(true);
        Log::info('▶️ DESCARGAR_MULTIPLES inicio', ['documentos' => $request->input('documentos')]);

        try {
            $request->validate([
                'documentos' => 'required|array|min:1',
                'documentos.*' => 'required|string',
            ]);

            $accesoTotal = $this->tieneAccesoTotal();
            $cveVend = $accesoTotal ? null : $this->getAgenteClave();
            $documentos = array_map('trim', $request->documentos);

            Log::info('🔑 DESCARGAR_MULTIPLES acceso', [
                'acceso_total' => $accesoTotal,
                'cveVend' => $cveVend,
                'cantidad_documentos' => count($documentos),
            ]);

            $placeholders = implode(',', array_fill(0, count($documentos), '?'));

            // ✅ Pasar $cveVend para que queryBase genere el filtro correcto
            $query = $this->queryBase($cveVend);
            $query .= " AND TRIM(DOCUMENTO) IN ({$placeholders})";
            $query .= ' ORDER BY FECHA_APLI DESC';

            // ✅ Params: solo documentos si acceso total, o [cveVend, ...docs] si no
            $params = $accesoTotal
                ? $documentos
                : array_merge([$cveVend], $documentos);

            $this->logQuery('DESCARGAR_MULTIPLES', $query, $params);

            $resultados = $this->fb()->select($query, $params);

            $this->logResultado('DESCARGAR_MULTIPLES', $resultados, $inicio);

            if (empty($resultados)) {
                Log::warning('⚠️ DESCARGAR_MULTIPLES sin resultados', ['documentos' => $documentos, 'cveVend' => $cveVend]);

                return response()->json(['success' => false, 'message' => 'No se encontraron documentos'], 404);
            }

            // Agrupar por cliente para el blade
            $porCliente = collect($resultados)->groupBy(fn ($r) => trim($r->CLAVE));

            Log::info('👥 DESCARGAR_MULTIPLES agrupado por cliente', [
                'clientes' => $porCliente->keys()->all(),
                'documentos_encontrados' => count($resultados),
                'documentos_solicitados' => count($documentos),
            ]);

            $data = [
                'por_cliente' => $porCliente,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
            ];

            Log::info('🖨️ DESCARGAR_MULTIPLES generando vista pdfs.estados-cuenta-multiples');

            $pdf = PDF::loadView('pdfs.estados-cuenta-multiples', $data);

            Log::info('✅ DESCARGAR_MULTIPLES PDF listo', ['ms' => round((microtime(true) - $inicio) * 1000, 2)]);

            return $pdf->download('estados-cuenta-'.date('YmdHis').'.pdf');
        } catch (\Exception $e) {
            $this->logError('ERROR_DESCARGAR_MULTIPLES', $e);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar PDF múltiple',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =======================================================
        📧 ENVIAR EMAIL
    ======================================================= */
    public function enviarEmail(Request $request, $noFactura)
    {
        $inicio = microtime(true);
        Log::info('▶️ ENVIAR_EMAIL inicio', ['documento' => $noFactura, 'email' => $request->input('email')]);

        try {
            $request->validate(['email' => 'required|email']);

            $cveVend = $this->getAgenteClave();

            $query = $this->queryBase();
            $query .= ' AND TRIM(DOCUMENTO) = TRIM(?)';

            $params = [$cveVend, $noFactura];

            // ⚠️ OJO: queryBase() sin $cveVend solo trae 1 "?" pero se mandan 2 params.
            // Este log lo va a marcar como DESAJUSTE DE BINDINGS si pasa.
            $this->logQuery('ENVIAR_EMAIL', $query, $params);

            $resultado = $this->fb()->selectOne($query, $params);

            $this->logResultado('ENVIAR_EMAIL', $resultado, $inicio);

            if (! $resultado) {
                Log::warning('⚠️ ENVIAR_EMAIL documento no encontrado', ['documento' => $noFactura, 'cveVend' => $cveVend]);

                return response()->json(['success' => false, 'message' => 'Documento no encontrado'], 404);
            }

            $data = [
                'documento' => $resultado,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
            ];

            Log::info('🖨️ ENVIAR_EMAIL generando PDF');

            $pdf = PDF::loadView('pdfs.estado-cuenta', $data);

            Log::info('📨 ENVIAR_EMAIL enviando correo', [
                'to' => $request->email,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.'.config('mail.default').'.host'),
            ]);

            Mail::send('emails.estado-cuenta', $data, function ($message) use ($request, $pdf, $noFactura) {
                $message->to($request->email)
                    ->subject('Estado de Cuenta - '.$noFactura)
                    ->attachData($pdf->output(), "estado-cuenta-{$noFactura}.pdf");
            });

            Log::info('✅ ENVIAR_EMAIL enviado', ['ms' => round((microtime(true) - $inicio) * 1000, 2)]);

            return response()->json(['success' => true, 'message' => 'Email enviado correctamente']);
        } catch (\Exception $e) {
            $this->logError('ERROR_ENVIAR_EMAIL', $e, ['documento' => $noFactura]);

            return response()->json(['success' => false, 'message' => 'Error al enviar email', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        🏗 GENERAR (NO APLICA)
    ======================================================= */
    public function generar(Request $request)
    {
        Log::info('▶️ GENERAR llamado (no implementado)');

        return response()->json([
            'success' => false,
            'message' => 'Funcionalidad no implementada - solo lectura desde Firebird',
        ], 501);
    }

    /* =======================================================
        🔄 ACTUALIZAR ESTADO
    ======================================================= */
    public function actualizarEstado(Request $request, $noFactura)
    {
        $inicio = microtime(true);
        Log::info('▶️ ACTUALIZAR_ESTADO inicio', ['documento' => $noFactura, 'status' => $request->input('status')]);

        try {
            $request->validate([
                'status' => 'required|string',
            ]);

            $clie = $this->getAgenteClave();

            $query = $this->queryBase();
            $query .= ' AND TRIM(DOCUMENTO) = TRIM(?)';

            $params = [$clie, $noFactura];

            // ⚠️ Mismo caso que enviarEmail: 1 "?" vs 2 params
            $this->logQuery('ACTUALIZAR_ESTADO_SELECT', $query, $params);

            $resultado = $this->fb()->selectOne($query, $params);

            $this->logResultado('ACTUALIZAR_ESTADO_SELECT', $resultado, $inicio);

            if (! $resultado) {
                Log::warning('⚠️ ACTUALIZAR_ESTADO documento no encontrado', ['documento' => $noFactura, 'cve_vend' => $clie]);

                return response()->json([
                    'success' => false,
                    'message' => 'Documento no encontrado',
                ], 404);
            }

            $updateSql = 'UPDATE '.tb('CLIE').' SET STATUS = ? WHERE TRIM(CVE_VEND) = TRIM(?)';
            $updateParams = [$request->status, $clie];

            $this->logQuery('ACTUALIZAR_ESTADO_UPDATE', $updateSql, $updateParams);

            $filas = $this->fb()->update($updateSql, $updateParams);

            Log::info('✅ ACTUALIZAR_ESTADO fin', [
                'filas_afectadas' => $filas,
                'ms' => round((microtime(true) - $inicio) * 1000, 2),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado correctamente',
            ]);
        } catch (\Exception $e) {
            $this->logError('ERROR_ACTUALIZAR_ESTADO', $e, ['documento' => $noFactura]);

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar estado',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* =======================================================
        🗑 DELETE (NO PERMITIDO)
    ======================================================= */
    public function destroy($noFactura)
    {
        Log::warning('⛔ DESTROY intento de eliminar', [
            'documento' => $noFactura,
            'user_id' => Auth::user()->ID ?? null,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'No se permite eliminar estados de cuenta',
        ], 403);
    }
}