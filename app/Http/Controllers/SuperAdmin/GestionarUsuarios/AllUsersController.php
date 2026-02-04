<?php

namespace App\Http\Controllers\SuperAdmin\GestionarUsuarios;

use App\Http\Controllers\Controller;
use App\Models\TaskParticipant;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AllUsersController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $q = trim($request->get('q', ''));
         $limit = (int) $request->get('limit', 50); // ✅ FIX
        $limit = $limit > 200 ? 200 : $limit;

        // Conexión a Firebird PRODUCCIÓN (igual que tus comandos)
        config([
            'database.connections.firebird_produccion' => [
                'driver'   => 'firebird',
                'host'     => env('FB_HOST'),
                'port'     => env('FB_PORT'),
                'database' => env('FB_DATABASE'),
                'username' => env('FB_USERNAME'),
                'password' => env('FB_PASSWORD'),
                'charset'  => env('FB_CHARSET', 'UTF8'),
                'dialect'  => 3,
                'quote_identifiers' => false,
            ]
        ]);

        DB::purge('firebird_produccion');
        $fb = DB::connection('firebird_produccion');

        // Query base
        $sql = "SELECT FIRST {$limit} ID, NOMBRE, CORREO, PHOTO
        FROM USUARIOS
        WHERE 1=1";


        $bindings = [];

        // Si quieres buscador (por nombre/correo)
        if ($q !== '') {
            $sql .= " AND (UPPER(NOMBRE) LIKE ? OR UPPER(CORREO) LIKE ?)";
            $like = '%' . strtoupper($q) . '%';
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $sql .= " ORDER BY NOMBRE";

        $rows = $fb->select($sql, $bindings);

        // Normalizar salida para el front
        $users = collect($rows)->map(function ($u) {
            return [
                'id' => (int) $u->ID,
                'nombre' => trim($u->NOMBRE ?? ''),
                'correo' => isset($u->CORREO) ? trim($u->CORREO) : null,
                'photo' => isset($u->PHOTO) ? trim($u->PHOTO) : null, // ✅
            ];
        })->values();

        return response()->json($users);
    }

    /**
     * Show the form for creating a new resource.
     * (En API normalmente no se usa)
     */
    public function create() {}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {}

    /**
     * Display the specified resource.
     */
    public function show(string $id) {}

    /**
     * Show the form for editing the specified resource.
     * (En API normalmente no se usa)
     */
    public function edit(string $id) {}

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id) {}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id) {}
}