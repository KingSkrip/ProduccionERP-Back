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
            $pedidos = DB::connection('firebird')
                ->table('PEDIDOSENC')
                ->where(function ($q) {
                    $q->where('AUTORIZACRED', 0)
                        ->orWhereNull('AUTORIZACRED');
                })
                ->orderBy('FECHAELAB', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $pedidos
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar PEDIDOSENC',
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
