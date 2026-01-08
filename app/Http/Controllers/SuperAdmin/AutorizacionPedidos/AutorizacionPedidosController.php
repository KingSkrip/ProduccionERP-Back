<?php

namespace App\Http\Controllers\SuperAdmin\AutorizacionPedidos;

use App\Models\create_departamentos_table;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AutorizacionPedidosController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $fbDatabase = env('FB_DATABASE');
            preg_match('/\d{2}/', $fbDatabase, $matches);
            $empresa = $matches[0] ?? '01';


            // 1️⃣ Pedidos (SP)
            $pedidos = DB::connection('firebird')->select("
            SELECT *
            FROM P_PEDIDOSENCMAIN(?)
            WHERE COALESCE(AUTORIZACRED, 0) = 0
              AND COALESCE(NESTATUS, 0) <> 99
              AND COALESCE(AUTORIZA, 0) = 0
            ORDER BY \"FECHA ELAB.\" DESC
        ", [$empresa]);

            // Si no hay pedidos
            if (empty($pedidos)) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ], 200);
            }

            // 2️⃣ IDs de pedidos
            $pedidoIds = array_map(fn($p) => (int) $p->ID, $pedidos);

            // 3️⃣ Artículos (una sola query)
            $articulos = DB::connection('firebird')->select("
            SELECT CVE_PED, ARTICULO, SUM(CANTIDAD) AS CANTIDAD
            FROM V_PED_PART
            WHERE CVE_PED IN (" . implode(',', $pedidoIds) . ")
            GROUP BY CVE_PED, ARTICULO
        ");

            // 4️⃣ Cardigan (una sola query)
            $cardigans = DB::connection('firebird')->select("
            SELECT CVE_PED,
                   \"CARDIGAN DESCR.\" AS DESCRIPCION,
                   SUM(\"CANT. CARD.\") AS CANTIDAD
            FROM V_PED_PART
            WHERE CVE_PED IN (" . implode(',', $pedidoIds) . ")
              AND \"CANT. CARD.\" > 0
            GROUP BY CVE_PED, \"CARDIGAN DESCR.\"
        ");

            // 5️⃣ Indexar artículos por pedido
            $articulosPorPedido = [];
            foreach ($articulos as $a) {
                $articulosPorPedido[$a->CVE_PED][] = $a;
            }

            // 6️⃣ Indexar cardigan por pedido
            $cardiganPorPedido = [];
            foreach ($cardigans as $c) {
                $cardiganPorPedido[$c->CVE_PED][] = $c;
            }

            // 7️⃣ Asignar a cada pedido
            foreach ($pedidos as $pedido) {
                $pedido->articulos = $articulosPorPedido[$pedido->ID] ?? [];
                $pedido->cardigan  = $cardiganPorPedido[$pedido->ID] ?? [];
            }

            // 8️⃣ Response
            return response()->json([
                'success' => true,
                'data' => $pedidos
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar pedidos con partidas',
                'error' => $e->getMessage()
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
            // 1️⃣ Buscar pedido en Firebird
            $pedido = DB::connection('firebird')
                ->table('PEDIDOSENC')
                ->where('ID', $id)
                ->first();

            // 2️⃣ Validar existencia
            if (!$pedido) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pedido no encontrado'
                ], 404);
            }

            // 3️⃣ Validar si ya está autorizado
            if ((int) $pedido->AUTORIZACRED === 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'El pedido ya está autorizado'
                ], 409);
            }

            // 4️⃣ Autorizar crédito (UPDATE Firebird)
            DB::connection('firebird')
                ->table('PEDIDOSENC')
                ->where('ID', $id)
                ->update([
                    'AUTORIZACRED' => 1,
                    'FECHAAUTC' => now()->format('Y-m-d H:i:s'),
                    'USAUTC'       => auth()->user()->id,
                ]);

            // 5️⃣ Respuesta OK
            return response()->json([
                'success' => true,
                'message' => 'Pedido autorizado correctamente'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al autorizar el pedido',
                'error' => $e->getMessage()
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
