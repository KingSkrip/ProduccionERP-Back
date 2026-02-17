<?php

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PedidosController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // 🔐 1. Obtener usuario logueado (Firebird ID)
        $user = Auth::user();

        Log::info('PedidosController@index → Inicio', [
            'user_id'     => $user?->id,
            'user_email'  => $user?->email,
            'authenticated' => Auth::check(),
        ]);

        if (!$user) {
            Log::warning('PedidosController@index → Usuario no autenticado');
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }

        // 🔎 2. Buscar identity del cliente (CLIE03)
        $identity = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->where('firebird_user_clave', $user->ID)           // ← revisa que la columna se llame exactamente ID (mayúsculas)
            ->where('firebird_clie_tabla', 'CLIE03')
            ->whereNotNull('firebird_clie_clave')
            ->first();

        Log::info('PedidosController@index → Búsqueda de identity', [
            'firebird_user_clave' => $user->ID,
            'encontrado'          => !is_null($identity),
            'identity'            => $identity ? (array)$identity : null,
        ]);

        if (!$identity) {
            Log::warning('PedidosController@index → No se encontró identidad CLIE03 para el usuario', [
                'laravel_user_id'     => $user->id,
                'firebird_user_clave' => $user->ID,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Este usuario no es cliente CLIE'
            ], 403);
        }

        $clieClave = $identity->firebird_clie_clave;

        Log::info('PedidosController@index → Cliente encontrado', [
            'clie_clave' => $clieClave,
        ]);

        // 🔥 3. Conexión a Firebird (debería estar configurada en config/database.php)
        try {
            $connection = DB::connection('firebird_produccion');
            $connection->getPdo(); // fuerza la conexión para detectar errores temprano

            Log::info('PedidosController@index → Conexión Firebird exitosa', [
                'connection_name' => 'firebird_produccion',
            ]);
        } catch (\Exception $e) {
            Log::error('PedidosController@index → Error al conectar a Firebird', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error de conexión a la base de datos de producción'
            ], 500);
        }

        // 🛒 4. Obtener pedidos
        try {
            $pedidos = $connection->select("
            SELECT 
                NUM_PEDIDO,
                FECHA,
                CLIE_CLAVE,
                TOTAL,
                ESTADO,
                -- agrega aquí otras columnas que realmente necesites
                -- evita SELECT * en producción (performance + seguridad)
            FROM PEDIDOS
            WHERE CLIE_CLAVE = ?
            ORDER BY FECHA DESC
        ", [$clieClave]);

            Log::info('PedidosController@index → Pedidos consultados', [
                'clie_clave'     => $clieClave,
                'cantidad'       => count($pedidos),
                'primer_pedido'  => $pedidos[0] ?? null,   // solo el primero para debug (no todo el array)
            ]);

            return response()->json([
                'success'       => true,
                'cliente_clave' => $clieClave,
                'pedidos'       => $pedidos,
                'debug_cantidad' => count($pedidos), // útil para el frontend mientras debugueas
            ]);
        } catch (\Exception $e) {
            Log::error('PedidosController@index → Error al consultar pedidos', [
                'clie_clave' => $clieClave,
                'query'      => "SELECT ... FROM PEDIDOS WHERE CLIE_CLAVE = '$clieClave'",
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los pedidos',
                'debug'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

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
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}