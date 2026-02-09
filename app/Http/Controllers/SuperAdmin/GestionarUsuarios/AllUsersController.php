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
        $limit = (int) $request->get('limit', 50);
        $limit = $limit > 200 ? 200 : $limit;

        // Firebird connection
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

        // 1) Si hay búsqueda: buscar primero en Firebird
        $fbIdsMatched = [];
        
        if ($q !== '') {
                $like = '%' . strtoupper(trim($q)) . '%';
    $limit = max(1, min($limit, 200));

    $sql = "
        SELECT FIRST $limit
            ID,
            NOMBRE,
            TRIM(CORREO) AS CORREO,
            PHOTO
        FROM USUARIOS
        WHERE (
            UPPER(NOMBRE) LIKE ?
            OR UPPER(TRIM(CORREO)) LIKE ?
        )
    ";

    $rows = $fb->select($sql, [$like, $like]);

    if (empty($rows)) {
        return response()->json([]);
    }


            $fbUsers = collect($rows)->keyBy(fn($u) => (int) $u->ID);
            $fbIdsMatched = $fbUsers->keys()->map(fn($x) => (int)$x)->values()->all();

            // traer identidades que existan para esos IDs
            $identities = DB::connection('mysql')
                ->table('users_firebird_identities')
                ->select('id as mysql_id', 'firebird_user_clave', 'firebird_empresa', 'firebird_tb_tabla')
                ->whereIn('firebird_user_clave', $fbIdsMatched)
                ->get()
                ->keyBy(fn($r) => (int)$r->firebird_user_clave);

            // merge con fallback (si no hay identity, igual lo devuelves)
            $result = collect($fbIdsMatched)->map(function ($fbId) use ($fbUsers, $identities) {
                $fbUser = $fbUsers->get((int)$fbId);
                $identity = $identities->get((int)$fbId);

                return [
                    'id' => (int) $fbId, // USUARIOS.ID
                    'mysql_id' => $identity ? (int)$identity->mysql_id : null,
                    'firebird_user_clave' => (int) $fbId,
                    'nombre' => $fbUser ? trim((string)($fbUser->NOMBRE ?? '')) : '',
                    'correo' => $fbUser && isset($fbUser->CORREO) ? trim((string)$fbUser->CORREO) : null,
                    'photo'  => $fbUser && isset($fbUser->PHOTO) ? trim((string)$fbUser->PHOTO) : null,
                    'has_identity' => (bool) $identity,
                ];
            })->values();

            return response()->json($result);
        }

        // 2) Si NO hay búsqueda: comportamiento viejo (listar por pivote)
        $identities = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->select('id as mysql_id', 'firebird_user_clave', 'firebird_empresa', 'firebird_tb_tabla')
            ->limit($limit)
            ->get();

        if ($identities->isEmpty()) {
            return response()->json([]);
        }

        $fbUserIds = $identities->pluck('firebird_user_clave')->map(fn($x) => (int)$x)->unique()->values()->all();
        $in = implode(',', array_map('intval', $fbUserIds));

        $fbUsers = collect($fb->select("
        SELECT ID, NOMBRE, CORREO, PHOTO
        FROM USUARIOS
        WHERE ID IN ($in)
    "))->keyBy(fn($u) => (int) $u->ID);

        $result = $identities->map(function ($row) use ($fbUsers) {
            $fbUser = $fbUsers->get((int) $row->firebird_user_clave);

            return [
                'id' => (int) $row->firebird_user_clave, // USUARIOS.ID
                'mysql_id' => (int) $row->mysql_id,
                'firebird_user_clave' => (int) $row->firebird_user_clave,
                'nombre' => $fbUser ? trim((string)($fbUser->NOMBRE ?? '')) : '',
                'correo' => $fbUser && isset($fbUser->CORREO) ? trim((string)$fbUser->CORREO) : null,
                'photo'  => $fbUser && isset($fbUser->PHOTO) ? trim((string)$fbUser->PHOTO) : null,
                'has_identity' => true,
            ];
        })->values();

        return response()->json($result);
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