<?php

namespace App\Http\Controllers\Agentes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class PedidosAgentesController extends Controller
{
    protected ?bool $accesoTotalCache = null;
    protected mixed $agenteClaveCacheValue = null;
    protected array $clientesBloqueados = [
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
        'ZURIZEN',
    ];

    protected function fb()
    {
        return DB::connection('firebird');
    }

    protected function getEmpresa(): string
    {
        $fbDatabase = env('FB_DATABASE', '');
        preg_match('/\d{2}/', $fbDatabase, $matches);
        $empresa = $matches[0] ?? '03';
        Log::info('empresa de pedidos', [
            'empresa'     => $empresa,
            'fb_database' => $fbDatabase,
        ]);

        return $empresa;
    }

    /* =======================================================
        🔐 HELPER - Verificar si tiene acceso total (sub_permission 9 o 10)
    ======================================================= */
    protected function tieneAccesoTotal(): bool
    {

        $user = Auth::user();
        if ($this->accesoTotalCache !== null) return $this->accesoTotalCache;
        if (!$user) return false;

        $identity = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->where('firebird_user_clave', $user->ID)
            ->first();

        if (!$identity) return false;

        $subPermissions = DB::connection('mysql')
            ->table('model_has_roles')
            ->where('firebird_identity_id', $identity->id)
            ->pluck('subrol_id')
            ->filter()
            ->toArray();

        Log::info('🔐 SUB_PERMISSIONS_CHECK (Agentes/Pedidos)', [
            'user_id'         => $user->ID,
            'identity_id'     => $identity->id,
            'sub_permissions' => $subPermissions,
            'tiene_acceso'    => in_array(9, $subPermissions) || in_array(10, $subPermissions),
        ]);

        return $this->accesoTotalCache = in_array(9, $subPermissions) || in_array(10, $subPermissions);
    }

    protected function getAgenteClave()
    {
        Log::info('🔍 Entrando a getAgenteClave');

        $user = Auth::user();
        if ($this->agenteClaveCacheValue !== null) return $this->agenteClaveCacheValue;
        if (!$user) {
            Log::warning('⛔ Usuario no autenticado');
            abort(401, 'No autenticado');
        }

        Log::info('👤 Usuario autenticado', [
            'user_id'      => $user->id ?? null,
            'firebird_ID'  => $user->ID ?? null,
            'email'        => $user->email ?? null,
        ]);

        $identity = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->where('firebird_user_clave', $user->ID)
            ->where('firebird_vend_tabla', 'VEND03')
            ->whereNotNull('firebird_vend_clave')
            ->first();

        if (!$identity) {
            Log::warning('⛔ No se encontró identidad vinculada', [
                'firebird_user_clave' => $user->ID,
            ]);
            abort(403, 'No es agente VEND');
        }

        Log::info('✅ Identidad encontrada', [
            'firebird_vend_clave' => $identity->firebird_vend_clave,
        ]);

        return $this->agenteClaveCacheValue = $identity->firebird_vend_clave;
    }

    protected function sanitize($value): string
    {
        return trim((string) ($value ?? ''));
    }

    /* =======================================================
        🛒 SP - todos los pedidos de la empresa
    ======================================================= */
    protected function getPedidosSP(): Collection
    {
        $empresa = $this->getEmpresa();
        static $cache = null;
        if ($cache !== null) return $cache;

        $pedidos = collect(
            $this->fb()->select("SELECT * FROM P_PEDIDOSENCMAIN(?)", [$empresa])
        )->filter(fn($item) => !empty(trim($item->CLIENTE ?? '')));

        if ($this->tieneAccesoTotal()) {
            $pedidos = $pedidos->filter(function ($item) {
                $nombre = strtoupper(trim($item->CLIENTE ?? ''));
                return !in_array($nombre, $this->clientesBloqueados);
            });
        }

        return $cache = $pedidos->values();
    }



    /**
     * Query directa reemplazando P_PEDIDOSENCMAIN
     * Firebird pagina con FIRST/SKIP — solo trae los registros necesarios
     */
    protected function getPedidosPaginadoDirecto(
        int $limit,
        int $offset,
        ?int $cveVend = null,
        bool $excluirBloqueados = false,
        ?string $condicion = null
    ): array {
        $empresa     = $this->getEmpresa();
        $whereAgente = $cveVend ? "AND P.AGENTE = '{$cveVend}'" : '';

        // Siempre solo parciales — hardcodeado
        $whereEstado = "AND P.PARCOCOMPL = 'P'";

        $whereCondicion = '';
        if ($condicion && $condicion !== 'todas') {
            $condicionMap = ['Credito' => 1, 'Sin definir' => 0];
            if (isset($condicionMap[$condicion])) {
                $whereCondicion = "AND P.COND = {$condicionMap[$condicion]}";
            }
        }

        $whereBloqueados = '';
        if ($excluirBloqueados && !empty($this->clientesBloqueados)) {
            $bloqueadosEscapados = array_map(
                fn($n) => "'" . addslashes(strtoupper($n)) . "'",
                $this->clientesBloqueados
            );
            $whereBloqueados = 'AND UPPER(TRIM(C.NOMBRE)) NOT IN (' . implode(',', $bloqueadosEscapados) . ')';
        }

        $filtrosComunes = "{$whereAgente} {$whereEstado} {$whereCondicion} {$whereBloqueados}";

        $countSql = "SELECT COUNT(*) AS TOTAL
                 FROM (
                     SELECT P.CVE_CTE
                     FROM PEDIDOSENC P
                     LEFT JOIN CLIE{$empresa} C ON C.CLAVE = P.CVE_CTE
                     WHERE P.ESTATUS IN (1, 2, 3)
                       AND P.CVE_CTE IS NOT NULL
                       AND TRIM(P.CVE_CTE) <> ''
                       {$filtrosComunes}
                     GROUP BY P.CVE_CTE
                 )";

        $countResult   = $this->fb()->select($countSql);
        $totalClientes = (int) ($countResult[0]->TOTAL ?? 0);

        if ($totalClientes === 0) {
            return ['pedidos' => collect(), 'totalClientes' => 0];
        }

        $clientesSql = "SELECT FIRST {$limit} SKIP {$offset}
                        P.CVE_CTE,
                        MIN(C.NOMBRE) AS NOMBRE_CTE
                    FROM PEDIDOSENC P
                    LEFT JOIN CLIE{$empresa} C ON C.CLAVE = P.CVE_CTE
                    WHERE P.ESTATUS IN (1, 2, 3)
                      AND P.CVE_CTE IS NOT NULL
                      AND TRIM(P.CVE_CTE) <> ''
                      {$filtrosComunes}
                    GROUP BY P.CVE_CTE
                    ORDER BY MIN(C.NOMBRE) ASC";

        $clientesPagina = collect($this->fb()->select($clientesSql))
            ->pluck('CVE_CTE')
            ->filter()
            ->values()
            ->toArray();

        if (empty($clientesPagina)) {
            return ['pedidos' => collect(), 'totalClientes' => $totalClientes];
        }

        $placeholders = implode(',', array_fill(0, count($clientesPagina), '?'));

        $sql = "SELECT
                P.ID, P.ANIO, P.PEDIDON, P.PEDIDO,
                CASE COALESCE(P.VE,0) WHEN 0 THEN 'NACIONAL' WHEN 1 THEN 'EXPORTACION' END AS TIPO_VENTA,
                P.ESTATUS AS NESTATUS,
                CASE COALESCE(P.ESTATUS,0)
                    WHEN 1 THEN 'ACTIVO' WHEN 2 THEN 'CON O.P.' WHEN 3 THEN 'PARCIAL'
                    WHEN 4 THEN 'COMPLETADO' WHEN 99 THEN 'CANCELADO' ELSE 'SIN ESTATUS' END AS ESTATUS,
                COALESCE(P.AUTORIZAVTAS,0) AS AUTORIZA,
                CASE COALESCE(P.AUTORIZAVTAS,0)+COALESCE(P.AUTORIZACRED,0)+COALESCE(P.AUTORIZADIR,0)+COALESCE(P.AUTORIZAESP,0)
                    WHEN 2 THEN 'AUTORIZADO' WHEN 3 THEN 'AUTORIZADO' WHEN 4 THEN 'AUTORIZADO'
                    ELSE 'POR AUTORIZAR' END AS AUTORIZADO,
                P.CVE_CTE,
                COALESCE(C.NOMBRE,'') AS CLIENTE,
                COALESCE(P.REFER_CTE,'') AS REFERENCIA,
                P.TIPO AS TIPON,
                CASE P.TIPO WHEN 0 THEN 'Z100' WHEN 1 THEN 'Z200' WHEN 2 THEN 'Z100/Z200' END AS TIPO,
                COALESCE(P.CREDITO,0) AS CREDITON,
                CASE COALESCE(P.CREDITO,0) WHEN 1 THEN 'SI' ELSE 'NO' END AS CREDITO,
                COALESCE(P.CREDITODIAS,0) AS DIAS_CREDITO,
                COALESCE(P.COND,0) AS CONDN,
                CASE COALESCE(P.COND,0)
                    WHEN 0 THEN 'Sin definir' WHEN 1 THEN 'Credito' WHEN 2 THEN 'Pago anticipado'
                    WHEN 3 THEN 'Porc. Anticipo' WHEN 4 THEN 'Contraentrega'
                    WHEN 5 THEN 'Fecha de pago' WHEN 6 THEN 'Monto de anticipo'
                    ELSE 'Sin definir' END AS CONDICIONES,
                P.AGENTE,
                COALESCE(A.NOMBRE,'') AS NOMBRE_AGENTE,
                P.FECHAELAB, P.FECHAENT, P.FECHAPAGO,
                P.USUARIO,
                COALESCE(U.NOMBRE,'') AS NOMBRE_USUARIO,
                COALESCE(O.OBS,'') AS OBSERVACIONES,
                P.TIPOPROD,
                COALESCE(P.PAGANTENT,0) AS PAGANTENT,
                COALESCE(P.AUTORIZACRED,0) AS AUTORIZACRED,
                COALESCE(P.AUTORIZADIR,0) AS AUTORIZADIR,
                COALESCE(P.AUTORIZAESP,0) AS AUTORIZAESP,
                COALESCE(P.UUID,'') AS UUID,
                COALESCE(P.SHOWHILAT,1) AS SHOWHILAT,
                COALESCE(P.VE,0) AS VE,
                CASE COALESCE(P.PARCOCOMPL,'')
                    WHEN 'C' THEN 'Completo' WHEN 'P' THEN 'Parcial'
                    ELSE 'Sin Def.' END AS PARC_O_COMPL
            FROM PEDIDOSENC P
            LEFT JOIN CLIE{$empresa} C ON C.CLAVE = P.CVE_CTE
            LEFT JOIN VEND{$empresa} A ON A.CVE_VEND = P.AGENTE
            LEFT JOIN PEDIDOSOBS O ON O.ID = P.PEDIDO
            LEFT JOIN P_USUARIOS U ON U.CLAVE = P.USUARIO
            WHERE P.CVE_CTE IN ({$placeholders})
              AND P.ESTATUS IN (1, 2, 3)
              AND P.PARCOCOMPL = 'P'
              {$whereCondicion}
            ORDER BY C.NOMBRE ASC, P.ID DESC";

        $pedidos = collect($this->fb()->select($sql, $clientesPagina));

        return ['pedidos' => $pedidos, 'totalClientes' => $totalClientes];
    }


    protected function mapPedidoDirecto(object $item, float $kgTotal = 0.0): array
    {
        return [
            'id'            => (int)   ($item->ID          ?? 0),
            'anio'          => (int)   ($item->ANIO         ?? 0),
            'cve_ped'       => $this->sanitize($item->PEDIDO        ?? ''),
            'pedido_n'      => $this->sanitize($item->PEDIDON       ?? ''),
            'cve_clie'      => $this->sanitize($item->CVE_CTE       ?? ''),
            'nombre'        => $this->sanitize($item->CLIENTE       ?? ''),
            'referencia'    => $this->sanitize($item->REFERENCIA    ?? ''),
            'tipo_venta'    => $this->sanitize($item->TIPO_VENTA    ?? ''),
            'estatus'       => $this->sanitize($item->ESTATUS       ?? ''),
            'autorizado'    => $this->sanitize($item->AUTORIZADO    ?? ''),
            'condicion'     => $this->sanitize($item->CONDICIONES   ?? ''),
            'credito'       => $this->sanitize($item->CREDITO       ?? 'NO'),
            'dias_credito'  => (int)   ($item->DIAS_CREDITO         ?? 0),
            'agente'        => $this->sanitize($item->NOMBRE_AGENTE ?? ''),
            'fecha_elab'    => $this->parseDate($item->FECHAELAB    ?? null),
            'fecha_entrega' => $this->parseDate($item->FECHAENT     ?? null),
            'fecha_pago'    => $this->parseDate($item->FECHAPAGO    ?? null),
            'usuario'       => $this->sanitize($item->NOMBRE_USUARIO ?? ''),
            'observaciones' => $this->sanitize($item->OBSERVACIONES ?? ''),
            'status'        => $this->sanitize($item->PARC_O_COMPL  ?? ''),
            'kg_total'      => $kgTotal,
            'articulos'     => [],
            'cardigans'     => [],
            'ordenes_proc'  => [],
        ];
    }

    /* =======================================================
        📦 Artículos y cardigans usando ID (int) del SP
    ======================================================= */
    protected function getPartidasPorIds(array $ids): array
    {
        if (empty($ids)) return [
            'articulos' => collect(),
            'cardigans' => collect(),
        ];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $articulos = $this->fb()->select(
            "SELECT CVE_PED, ARTICULO, SUM(CANTIDAD) AS CANTIDAD
             FROM V_PED_PART
             WHERE CVE_PED IN ({$placeholders})
             GROUP BY CVE_PED, ARTICULO",
            $ids
        );

        $cardigans = $this->fb()->select(
            'SELECT CVE_PED, "CARDIGAN DESCR." AS DESCRIPCION, SUM("CANT. CARD.") AS CANTIDAD
             FROM V_PED_PART
             WHERE CVE_PED IN (' . $placeholders . ')
               AND "CANT. CARD." > 0
             GROUP BY CVE_PED, "CARDIGAN DESCR."',
            $ids
        );

        return [
            'articulos' => collect($articulos)->groupBy(fn($r) => (string) $r->CVE_PED),
            'cardigans' => collect($cardigans)->groupBy(fn($r) => (string) $r->CVE_PED),
        ];
    }

    protected function getStOrdenesPorIds(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) return collect();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $resultados = $this->fb()->select(
            "SELECT 
            OE.CVE_PED,
            OP.PROC,
            OP.ST,
            OP.ORDEN,
            OP.CONSPROC
         FROM ORDENESENC OE
         INNER JOIN ORDENESPROC OP ON OP.CVE_ORDEN = OE.ID
         WHERE OE.CVE_PED IN ({$placeholders})
         ORDER BY OE.CVE_PED, OP.CONSPROC",
            $ids
        );

        return collect($resultados)->groupBy(fn($r) => (string) $r->CVE_PED);
    }

    /* =======================================================
        🔧 mapPedido
    ======================================================= */
    protected function mapPedido(object $item, array $articulos = [], array $cardigans = [], array $ordenes = [], float $kgTotal = 0.0): array

    {
        return [
            'id'            => (int)   ($item->ID      ?? 0),
            'anio'          => (int)   ($item->ANIO     ?? 0),
            'cve_ped'       => $this->sanitize($item->PEDIDO    ?? ''),
            'pedido_n'      => $this->sanitize($item->PEDIDON   ?? ''),
            'cve_clie'      => $this->sanitize($item->CVE_CTE   ?? ''),
            'nombre'        => $this->sanitize($item->CLIENTE    ?? ''),
            'referencia'    => $this->sanitize($item->REFERENCIA ?? ''),
            'tipo_venta'    => $this->sanitize($item->{'TIPO VENTA'}    ?? ''),
            'estatus'       => $this->sanitize($item->ESTATUS            ?? ''),
            'autorizado'    => $this->sanitize($item->AUTORIZADO         ?? ''),
            'condicion'     => $this->sanitize($item->CONDICIONES        ?? ''),
            'credito'       => $this->sanitize($item->CREDITO            ?? 'NO'),
            'dias_credito'  => (int) ($item->{'DIAS DE CREDITO'}         ?? 0),
            'agente'        => $this->sanitize($item->{'NOMBRE AGENTE'}  ?? ''),
            'fecha_elab'    => $this->parseDate($item->{'FECHA ELAB.'}   ?? null),
            'fecha_entrega' => $this->parseDate($item->{'FECHA ENT.'}    ?? null),
            'fecha_pago'    => $this->parseDate($item->{'FECHA PAGO'}    ?? null),
            'usuario'       => $this->sanitize($item->USUARIO            ?? ''),
            'observaciones' => $this->sanitize($item->OBSERVACIONES      ?? ''),
            'status'        => $this->sanitize($item->{'PARC. O COMPL.'} ?? ''),

            'kg_total'      => $kgTotal,
            'articulos'     => array_map(fn($a) => (array) $a, $articulos),
            'cardigans'     => array_map(fn($c) => (array) $c, $cardigans),
            'ordenes_proc' => array_map(fn($o) => (array) $o, $ordenes),
        ];
    }

    protected function parseDate($value): ?string
    {
        if (empty($value)) return null;
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /* =======================================================
        📄 INDEX
    ======================================================= */

    public function index(Request $request)
    {
        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $page      = max(1, (int) $request->get('page', 1));
            $perPage   = max(1, min(50, (int) $request->get('per_page', 5)));
            $offset    = ($page - 1) * $perPage;
            $condicion = $request->get('condicion', 'todas') ?: 'todas';

            $cveVend           = $accesoTotal ? null : (int) $this->getAgenteClave();
            $excluirBloqueados = $accesoTotal;

            $resultado = $this->getPedidosPaginadoDirecto(
                $perPage,
                $offset,
                $cveVend,
                $excluirBloqueados,
                $condicion
            );

            $pedidosPagina = $resultado['pedidos'];
            $totalClientes = $resultado['totalClientes'];
            $totalPages    = max(1, (int) ceil($totalClientes / $perPage));

            $ids   = $pedidosPagina->pluck('ID')->filter()->map(fn($id) => (int) $id)->values()->toArray();
            $kilos = $this->getKilosPorIds($ids);

            $pedidos = $pedidosPagina->map(function ($item) use ($kilos) {
                $idStr   = (string) ($item->ID ?? '');
                $kg      = $kilos->get($idStr);
                $kgTotal = $kg
                    ? (float) ($kg->KG_ARTICULOS ?? 0) + (float) ($kg->KG_CARDIGANS ?? 0)
                    : 0.0;
                return $this->mapPedidoDirecto($item, $kgTotal);
            })->values();

            return response()->json([
                'success'    => true,
                'data'       => $pedidos,
                'total'      => $pedidos->count(),
                'pagination' => [
                    'page'          => $page,
                    'per_page'      => $perPage,
                    'total_clients' => $totalClientes,
                    'total_pages'   => $totalPages,
                    'has_next'      => $page < $totalPages,
                    'has_prev'      => $page > 1,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_INDEX_PEDIDOS', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error al obtener pedidos'], 500);
        }
    }



    // En index() — elimina las 2 queries pesadas, solo devuelve encabezados
    // public function index(Request $request)
    // {
    //     try {
    //         $accesoTotal = $this->tieneAccesoTotal();
    //         $pedidosSP   = $this->getPedidosSP();

    //         if (!$accesoTotal) {
    //             $cveVend   = (int) $this->getAgenteClave();
    //             $pedidosSP = $pedidosSP->filter(fn($item) => (int) ($item->AGENTE ?? 0) === $cveVend);
    //         }

    //         $pedidos = $pedidosSP
    //             ->sortByDesc(fn($item) => $item->{'FECHA ELAB.'} ?? '')
    //             ->values()
    //             ->map(fn($item) => $this->mapPedido($item)) // sin artículos ni órdenes
    //             ->values();

    //         return response()->json([
    //             'success' => true,
    //             'data'    => $pedidos,
    //             'total'   => $pedidos->count(),
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('ERROR_INDEX_PEDIDOS', ['message' => $e->getMessage()]);
    //         return response()->json(['success' => false, 'message' => 'Error al obtener pedidos'], 500);
    //     }
    // }


    /**
     * Solo suma de kg por pedido — mucho más ligero que getPartidasPorIds()
     */
    protected function getKilosPorIds(array $ids): \Illuminate\Support\Collection
    {
        if (empty($ids)) return collect();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $resultados = $this->fb()->select(
            "SELECT CVE_PED, SUM(CANTIDAD) AS KG_ARTICULOS, SUM(\"CANT. CARD.\") AS KG_CARDIGANS
         FROM V_PED_PART
         WHERE CVE_PED IN ({$placeholders})
         GROUP BY CVE_PED",
            $ids
        );

        return collect($resultados)->keyBy(fn($r) => (string) $r->CVE_PED);
    }

    // NUEVO endpoint — solo se llama al expandir un pedido
    public function detalle(string $cvePed)
    {
        try {
            $empresa     = $this->getEmpresa();
            $accesoTotal = $this->tieneAccesoTotal();

            // Verifica acceso buscando directamente en la tabla
            $check = $this->fb()->select(
                "SELECT FIRST 1 P.ID, P.AGENTE
             FROM PEDIDOSENC P
             WHERE P.PEDIDO = ?",
                [$cvePed]
            );

            if (empty($check)) {
                return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);
            }

            $pedido = $check[0];

            if (!$accesoTotal) {
                $cveVend = (int) $this->getAgenteClave();
                if ((int) ($pedido->AGENTE ?? 0) !== $cveVend) {
                    return response()->json(['success' => false, 'message' => 'Sin acceso'], 403);
                }
            }

            $id    = (int) $pedido->ID;
            $idStr = (string) $id;

            $extras   = $this->getPartidasPorIds([$id]);
            $stOrdens = $this->getStOrdenesPorIds([$id]);

            return response()->json([
                'success'   => true,
                'articulos' => $extras['articulos']->get($idStr, collect())->values(),
                'cardigans' => $extras['cardigans']->get($idStr, collect())->values(),
                'ordenes'   => $stOrdens->get($idStr, collect())->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_DETALLE_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
            return response()->json(['success' => false, 'message' => 'Error al obtener detalle'], 500);
        }
    }

    /* =======================================================
        🔍 SHOW
    ======================================================= */
    public function show(string $cvePed)
    {
        try {
            $accesoTotal = $this->tieneAccesoTotal();

            $resultado = $this->getPedidosSP()->first(function ($item) use ($cvePed, $accesoTotal) {
                $matchPedido = $this->sanitize($item->PEDIDO ?? '') === $this->sanitize($cvePed);

                if ($accesoTotal) return $matchPedido;

                $cveVend = (int) $this->getAgenteClave();
                return (int) ($item->AGENTE ?? 0) === $cveVend && $matchPedido;
            });

            if (!$resultado) return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

            $id     = (int) ($resultado->ID ?? 0);
            $idStr  = (string) $id;
            $extras   = $this->getPartidasPorIds([$id]);
            $stOrdens = $this->getStOrdenesPorIds([$id]);

            return response()->json(['success' => true, 'data' => $this->mapPedido(
                $resultado,
                $extras['articulos']->get($idStr, collect())->values()->toArray(),
                $extras['cardigans']->get($idStr, collect())->values()->toArray(),
                $stOrdens->get($idStr, collect())->values()->toArray()
            )]);
        } catch (\Exception $e) {
            Log::error('ERROR_SHOW_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
            return response()->json(['success' => false, 'message' => 'Error al obtener el pedido', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📊 RESUMEN
    ======================================================= */
    // public function resumen()
    // {
    //     try {
    //         $accesoTotal = $this->tieneAccesoTotal();

    //         $datos = $this->getPedidosSP();

    //         if (!$accesoTotal) {
    //             $cveVend = (int) $this->getAgenteClave();
    //             $datos   = $datos->filter(fn($item) => (int) ($item->AGENTE ?? 0) === $cveVend);
    //         }

    //         $hoy      = Carbon::now();
    //         $vencidos = $datos->filter(function ($item) use ($hoy) {
    //             $fecha = $item->{'FECHA ENT.'} ?? null;
    //             if (empty($fecha)) return false;
    //             try {
    //                 return Carbon::parse($fecha)->lt($hoy);
    //             } catch (\Exception $e) {
    //                 return false;
    //             }
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'data'    => [
    //                 'total_pedidos'    => $datos->count(),
    //                 'pedidos_vencidos' => $vencidos->count(),
    //                 'completos'        => $datos->filter(fn($i) => $this->sanitize($i->{'PARC. O COMPL.'} ?? '') === 'Completo')->count(),
    //                 'parciales'        => $datos->filter(fn($i) => $this->sanitize($i->{'PARC. O COMPL.'} ?? '') === 'Parcial')->count(),
    //                 'sin_def'          => $datos->filter(fn($i) => str_contains($this->sanitize($i->{'PARC. O COMPL.'} ?? ''), 'Sin'))->count(),
    //             ],
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('ERROR_RESUMEN_PEDIDOS', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    //         return response()->json(['success' => false, 'message' => 'Error al obtener resumen', 'error' => $e->getMessage()], 500);
    //     }
    // }

    public function resumen()
    {
        try {
            $accesoTotal = $this->tieneAccesoTotal();
            $empresa     = $this->getEmpresa();

            $cveVend           = $accesoTotal ? null : (int) $this->getAgenteClave();
            $excluirBloqueados = $accesoTotal;

            // ── Filtros reutilizables ──────────────────────────────────
            $whereAgente = $cveVend ? "AND P.AGENTE = '{$cveVend}'" : '';

            $whereBloqueados = '';
            if ($excluirBloqueados && !empty($this->clientesBloqueados)) {
                $bloqueadosEscapados = array_map(
                    fn($n) => "'" . addslashes(strtoupper($n)) . "'",
                    $this->clientesBloqueados
                );
                $whereBloqueados = 'AND UPPER(TRIM(C.NOMBRE)) NOT IN (' . implode(',', $bloqueadosEscapados) . ')';
            }

            // ── Conteos ───────────────────────────────────────────────
            $sql = "SELECT
                    COUNT(*)                                              AS TOTAL,
                    SUM(CASE WHEN P.PARCOCOMPL = 'C' THEN 1 ELSE 0 END)  AS COMPLETOS,
                    SUM(CASE WHEN P.PARCOCOMPL = 'P' THEN 1 ELSE 0 END)  AS PARCIALES,
                    SUM(CASE WHEN (P.PARCOCOMPL IS NULL OR (P.PARCOCOMPL <> 'C' AND P.PARCOCOMPL <> 'P')) THEN 1 ELSE 0 END) AS SIN_DEF
                FROM PEDIDOSENC P
                LEFT JOIN CLIE{$empresa} C ON C.CLAVE = P.CVE_CTE
                WHERE P.ESTATUS IN (1, 2, 3)
                  AND P.CVE_CTE IS NOT NULL
                  AND TRIM(P.CVE_CTE) <> ''
                  {$whereAgente}
                  {$whereBloqueados}";

            $conteos = $this->fb()->select($sql);
            $row     = $conteos[0] ?? null;

            // ── Vencidos ──────────────────────────────────────────────
            $hoy = Carbon::now()->format('Y-m-d');

            $sqlVencidos = "SELECT COUNT(*) AS VENCIDOS
                        FROM PEDIDOSENC P
                        LEFT JOIN CLIE{$empresa} C ON C.CLAVE = P.CVE_CTE
                        WHERE P.ESTATUS IN (1, 2, 3)
                          AND P.CVE_CTE IS NOT NULL
                          AND TRIM(P.CVE_CTE) <> ''
                          AND P.FECHAENT < '{$hoy}'
                          {$whereAgente}
                          {$whereBloqueados}";

            $vencidosResult = $this->fb()->select($sqlVencidos);
            $vencidos       = (int) ($vencidosResult[0]->VENCIDOS ?? 0);

            // ── Kg totales desde V_PED_PART ───────────────────────────
            $sqlKg = "SELECT SUM(VP.CANTIDAD + VP.\"CANT. CARD.\") AS TOTAL_KG
                  FROM V_PED_PART VP
                  INNER JOIN PEDIDOSENC P ON P.ID = VP.CVE_PED
                  LEFT JOIN CLIE{$empresa} C ON C.CLAVE = P.CVE_CTE
                  WHERE P.ESTATUS IN (1, 2, 3)
                    AND P.CVE_CTE IS NOT NULL
                    AND TRIM(P.CVE_CTE) <> ''
                    {$whereAgente}
                    {$whereBloqueados}";

            $kgResult = $this->fb()->select($sqlKg);
            $totalKg  = (float) ($kgResult[0]->TOTAL_KG ?? 0);

            return response()->json([
                'success' => true,
                'data'    => [
                    'total_pedidos'    => (int) ($row->TOTAL     ?? 0),
                    'pedidos_vencidos' => $vencidos,
                    'completos'        => (int) ($row->COMPLETOS ?? 0),
                    'parciales'        => (int) ($row->PARCIALES ?? 0),
                    'sin_def'          => (int) ($row->SIN_DEF   ?? 0),
                    'total_kg'         => $totalKg,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_RESUMEN_PEDIDOS', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Error al obtener resumen', 'error' => $e->getMessage()], 500);
        }
    }


    /* =======================================================
        📅 POR AÑO
    ======================================================= */
    public function porAnio(int $anio)
    {
        try {
            $accesoTotal = $this->tieneAccesoTotal();

            $pedidosSP = $this->getPedidosSP()
                ->filter(function ($item) use ($anio, $accesoTotal) {
                    $matchAnio = (int) ($item->ANIO ?? 0) === $anio;

                    if ($accesoTotal) return $matchAnio;

                    $cveVend = (int) $this->getAgenteClave();
                    return (int) ($item->AGENTE ?? 0) === $cveVend && $matchAnio;
                })
                ->sortByDesc(fn($item) => $item->{'FECHA ELAB.'} ?? '')
                ->values();

            $ids      = $pedidosSP->pluck('ID')->filter()->map(fn($id) => (int) $id)->values()->toArray();
            $extras   = $this->getPartidasPorIds($ids);
            $stOrdens = $this->getStOrdenesPorIds($ids); // 👈

            $pedidos = $pedidosSP->map(function ($item) use ($extras, $stOrdens) {
                $idStr     = (string) ($item->ID ?? '');
                $articulos = $extras['articulos']->get($idStr, collect())->values()->toArray();
                $cardigans = $extras['cardigans']->get($idStr, collect())->values()->toArray();
                $ordenes   = $stOrdens->get($idStr, collect())->values()->toArray(); // 👈
                return $this->mapPedido($item, $articulos, $cardigans, $ordenes);
            })->values();

            return response()->json(['success' => true, 'anio' => $anio, 'data' => $pedidos, 'total' => $pedidos->count()]);
        } catch (\Exception $e) {
            Log::error('ERROR_POR_ANIO_PEDIDOS', ['message' => $e->getMessage(), 'anio' => $anio]);
            return response()->json(['success' => false, 'message' => 'Error al obtener pedidos por año', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📄 PDF
    ======================================================= */
    public function descargarPDF(string $cvePed)
    {
        try {
            $accesoTotal = $this->tieneAccesoTotal();

            $resultado = $this->getPedidosSP()->first(function ($item) use ($cvePed, $accesoTotal) {
                $matchPedido = $this->sanitize($item->PEDIDO ?? '') === $this->sanitize($cvePed);

                if ($accesoTotal) return $matchPedido;

                $cveVend = (int) $this->getAgenteClave();
                return (int) ($item->AGENTE ?? 0) === $cveVend && $matchPedido;
            });

            if (!$resultado) return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

            $idStr  = (string) ($resultado->ID ?? '');
            $extras = $this->getPartidasPorIds([(int) ($resultado->ID ?? 0)]);

            $pdf = Pdf::loadView('pdfs.pedido', [
                'pedido'           => $this->mapPedido(
                    $resultado,
                    $extras['articulos']->get($idStr, collect())->values()->toArray(),
                    $extras['cardigans']->get($idStr, collect())->values()->toArray()
                ),
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
            ]);

            return $pdf->download("pedido-{$cvePed}.pdf");
        } catch (\Exception $e) {
            Log::error('ERROR_DESCARGAR_PDF_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
            return response()->json(['success' => false, 'message' => 'Error al generar PDF', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📦 DESCARGAR MÚLTIPLES
    ======================================================= */
    public function descargarMultiples(Request $request)
    {
        try {
            $request->validate(['pedidos' => 'required|array|min:1', 'pedidos.*' => 'required|string']);

            $accesoTotal = $this->tieneAccesoTotal();
            $cvePedidos  = array_map([$this, 'sanitize'], $request->pedidos);

            $pedidosSP = $this->getPedidosSP()->filter(function ($item) use ($cvePedidos, $accesoTotal) {
                $matchPedido = in_array($this->sanitize($item->PEDIDO ?? ''), $cvePedidos);

                if ($accesoTotal) return $matchPedido;

                $cveVend = (int) $this->getAgenteClave();
                return (int) ($item->AGENTE ?? 0) === $cveVend && $matchPedido;
            })->values();

            if ($pedidosSP->isEmpty()) return response()->json(['success' => false, 'message' => 'No se encontraron pedidos'], 404);

            $ids    = $pedidosSP->pluck('ID')->filter()->map(fn($id) => (int) $id)->values()->toArray();
            $extras = $this->getPartidasPorIds($ids);

            $pedidos = $pedidosSP->map(function ($item) use ($extras) {
                $idStr = (string) ($item->ID ?? '');
                return $this->mapPedido(
                    $item,
                    $extras['articulos']->get($idStr, collect())->values()->toArray(),
                    $extras['cardigans']->get($idStr, collect())->values()->toArray()
                );
            })->values();

            $pdf = Pdf::loadView('pdfs.pedidos-multiples', [
                'pedidos'          => $pedidos,
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
            ]);

            return $pdf->download("pedidos-" . date('YmdHis') . ".pdf");
        } catch (\Exception $e) {
            Log::error('ERROR_DESCARGAR_MULTIPLES_PEDIDOS', ['message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Error al generar PDF múltiple', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        📧 EMAIL
    ======================================================= */
    public function enviarEmail(Request $request, string $cvePed)
    {
        try {
            $request->validate(['email' => 'required|email']);

            $accesoTotal = $this->tieneAccesoTotal();

            $resultado = $this->getPedidosSP()->first(function ($item) use ($cvePed, $accesoTotal) {
                $matchPedido = $this->sanitize($item->PEDIDO ?? '') === $this->sanitize($cvePed);

                if ($accesoTotal) return $matchPedido;

                $cveVend = (int) $this->getAgenteClave();
                return (int) ($item->AGENTE ?? 0) === $cveVend && $matchPedido;
            });

            if (!$resultado) return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

            $idStr  = (string) ($resultado->ID ?? '');
            $extras = $this->getPartidasPorIds([(int) ($resultado->ID ?? 0)]);
            $data   = [
                'pedido'           => $this->mapPedido(
                    $resultado,
                    $extras['articulos']->get($idStr, collect())->values()->toArray(),
                    $extras['cardigans']->get($idStr, collect())->values()->toArray()
                ),
                'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
            ];

            $pdf = Pdf::loadView('pdfs.pedido', $data);
            Mail::send('emails.pedido', $data, function ($message) use ($request, $pdf, $cvePed) {
                $message->to($request->email)->subject('Pedido - ' . $cvePed)->attachData($pdf->output(), "pedido-{$cvePed}.pdf");
            });

            return response()->json(['success' => true, 'message' => 'Email enviado correctamente']);
        } catch (\Exception $e) {
            Log::error('ERROR_ENVIAR_EMAIL_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
            return response()->json(['success' => false, 'message' => 'Error al enviar email', 'error' => $e->getMessage()], 500);
        }
    }

    /* =======================================================
        🗑 DELETE (no permitido)
    ======================================================= */
    public function destroy(string $cvePed)
    {
        return response()->json(['success' => false, 'message' => 'No se permite eliminar pedidos'], 403);
    }
}