<?php

namespace App\Http\Controllers\SuperAdmin\AutorizacionPedidos;

use App\Http\Controllers\Controller;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutorizacionPedidosController extends Controller
{

    protected FirebirdEmpresaService $empresaService;
    protected FirebirdConnectionService $firebirdService;
    protected $fb;

    public function __construct(
        FirebirdEmpresaService $empresaService,
        FirebirdConnectionService $firebirdService
    ) {
        $this->empresaService = $empresaService;
        $this->firebirdService = $firebirdService;
    }

    protected function fb()
    {
        if (!$this->fb) {
            $this->fb = $this->firebirdService->getProductionConnection();
        }

        return $this->fb;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $empresa = $this->empresaService->getEmpresa();

            // 1️⃣ Pedidos
            $pedidos = $this->fb()->select('
            SELECT *
            FROM P_PEDIDOSENCMAIN(?)
            WHERE COALESCE(AUTORIZACRED, 0) = 0
              AND COALESCE(NESTATUS, 0) <> 99
            ORDER BY "FECHA ELAB." DESC
        ', [$empresa]);

            if (empty($pedidos)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                ]);
            }

            // 2️⃣ IDs
            $pedidoIds = array_map(fn($p) => (int) $p->ID, $pedidos);

            $placeholders = implode(',', array_fill(0, count($pedidoIds), '?'));

            // 3️⃣ Artículos
            $articulos = $this->fb()->select("
            SELECT CVE_PED, ARTICULO, SUM(CANTIDAD) AS CANTIDAD
            FROM V_PED_PART
            WHERE CVE_PED IN ($placeholders)
            GROUP BY CVE_PED, ARTICULO
        ", $pedidoIds);

            // 4️⃣ Cardigans
            $cardigans = $this->fb()->select("
            SELECT CVE_PED,
                   \"CARDIGAN DESCR.\" AS DESCRIPCION,
                   SUM(\"CANT. CARD.\") AS CANTIDAD
            FROM V_PED_PART
            WHERE CVE_PED IN ($placeholders)
              AND \"CANT. CARD.\" > 0
            GROUP BY CVE_PED, \"CARDIGAN DESCR.\"
        ", $pedidoIds);

            // 5️⃣ Agrupar
            $articulosPorPedido = collect($articulos)->groupBy('CVE_PED');
            $cardiganPorPedido  = collect($cardigans)->groupBy('CVE_PED');

            // 6️⃣ Asignar
            foreach ($pedidos as $pedido) {
                $pedido->articulos = $articulosPorPedido[$pedido->ID] ?? [];
                $pedido->cardigan  = $cardiganPorPedido[$pedido->ID] ?? [];
            }

            return response()->json([
                'success' => true,
                'data' => $pedidos,
            ]);
        } catch (\Exception $e) {
            Log::error('ERROR_AUTORIZACION_INDEX', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar pedidos',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    //     public function index()
    // {
    //     try {
    //         $pedidos = DB::connection('firebird')
    //             ->select("
    //                 SELECT *
    //                 FROM PEDIDOSENC
    //                 WHERE COALESCE(AUTORIZACRED, 0) = 0
    //                 ORDER BY FECHAELAB DESC
    //             ");

    //         return response()->json([
    //             'success' => true,
    //             'data' => $pedidos
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al consultar PEDIDOSENC',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show()
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit()
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $pedido = $this->fb()
                ->table('PEDIDOSENC')
                ->where('ID', $id)
                ->first();

            if (!$pedido) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pedido no encontrado',
                ], 404);
            }

            if ((int) $pedido->AUTORIZACRED === 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'El pedido ya está autorizado',
                ], 409);
            }

            $this->fb()
                ->table('PEDIDOSENC')
                ->where('ID', $id)
                ->update([
                    'AUTORIZACRED' => 1,
                    'FECHAAUTC'    => now()->format('Y-m-d H:i:s'),
                    'USAUTC'       => auth()->user()->id,
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Pedido autorizado correctamente',
            ]);
        } catch (Exception $e) {
            Log::error('ERROR_AUTORIZAR_PEDIDO', [
                'message' => $e->getMessage(),
                'id'      => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al autorizar el pedido',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy()
    {
        //
    }
}