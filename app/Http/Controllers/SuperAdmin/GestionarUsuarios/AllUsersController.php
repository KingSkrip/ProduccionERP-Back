<?php

namespace App\Http\Controllers\SuperAdmin\GestionarUsuarios;

use App\Http\Controllers\Controller;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaManualService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AllUsersController extends Controller
{

    protected FirebirdConnectionService $firebirdService;
    public function __construct(FirebirdConnectionService $firebirdService)
    {

        $this->firebirdService = $firebirdService;
    }
    /**
     * IDs de USUARIOS (Firebird) a excluir por duplicados u otras razones.
     * Agrega aquí los IDs que no quieres que aparezcan.
     */
    private array $excludedFirebirdUserIds = [
        2,
        3,
        4,
        5,
        6,
        7,
        9,
        10,
        16,
        18,
        24,
        29,
        32,
        38,
        46,
        52,
        54,
        67,
        68,
        69,
        71,
        73,
        75,
        79,
        83,
        84,
        89,
        90,
        91,
        92,
        93,
        94,
        97,
        98,
        100
    ];

    public function index(Request $request)
    {
        $q     = trim($request->get('q', ''));
        $limit = min((int) $request->get('limit', 50), 200);

        // PASO 1: Traer TODAS las identidades (con o sin TB)
        $identities = DB::connection('mysql')
            ->table('users_firebird_identities')
            ->select(
                'id as mysql_id',
                'firebird_user_clave',
                'firebird_tb_clave',
                'firebird_tb_tabla',
                'firebird_empresa'
            )
            // ❌ Quitamos: ->whereNotNull('firebird_tb_clave')
            ->whereNotNull('firebird_user_clave')          // ← solo que tenga usuario Firebird
            ->whereNotIn('firebird_user_clave', $this->excludedFirebirdUserIds)
            ->get();

        if ($identities->isEmpty()) {
            return response()->json([]);
        }

        $fbUserIds = $identities
            ->pluck('firebird_user_clave')
            ->map(fn($x) => (int) $x)
            ->unique()
            ->values()
            ->all();

        // PASO 2: Firebird USUARIOS (sin cambios)
        $this->firebirdService->getProductionConnection();
        $fb = DB::connection('firebird_produccion');

        $in      = implode(',', array_map('intval', $fbUserIds));
        $fbUsers = collect(
            $fb->select("SELECT ID, NOMBRE, TRIM(CORREO) AS CORREO, PHOTO FROM USUARIOS WHERE ID IN ($in)")
        )->keyBy(fn($u) => (int) $u->ID);

        // PASO 3: TB por empresa — SOLO los que tienen firebird_tb_clave
        $tbDataByEmpresa     = [];
        $identitiesConTb     = $identities->filter(fn($i) => !is_null($i->firebird_tb_clave));
        $identitiesByEmpresa = $identitiesConTb->groupBy('firebird_empresa');

        foreach ($identitiesByEmpresa as $empresa => $grupoIdentities) {
            try {
                $firebirdNoi = new FirebirdEmpresaManualService($empresa ?? '04', 'SRVNOI');
                $tbRows = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE));

                foreach ($grupoIdentities as $identity) {
                    $tbClave = trim((string) $identity->firebird_tb_clave);
                    $tbDataByEmpresa[$identity->firebird_user_clave] = $tbRows[$tbClave] ?? null;
                }
            } catch (\Throwable $e) {
                foreach ($grupoIdentities as $identity) {
                    $tbDataByEmpresa[$identity->firebird_user_clave] = null;
                }
            }
        }

        // PASO 4: Filtro por búsqueda
        if ($q !== '') {
            $qUpper     = strtoupper($q);
            $identities = $identities->filter(function ($identity) use ($fbUsers, $qUpper) {
                $fbUser = $fbUsers->get((int) $identity->firebird_user_clave);
                $nombre = strtoupper(trim((string) ($fbUser->NOMBRE ?? '')));
                $correo = strtoupper(trim((string) ($fbUser->CORREO ?? '')));
                return str_contains($nombre, $qUpper) || str_contains($correo, $qUpper);
            });
        }

        // PASO 5: Armar respuesta
        $result = $identities
            ->take($limit)
            ->map(function ($identity) use ($fbUsers, $tbDataByEmpresa) {
                $fbId   = (int) $identity->firebird_user_clave;
                $fbUser = $fbUsers->get($fbId);
                $tbRow  = $tbDataByEmpresa[$fbId] ?? null;

                return [
                    'id'                  => $fbId,
                    'mysql_id'            => (int) $identity->mysql_id,
                    'firebird_user_clave' => $fbId,
                    'firebird_tb_clave'   => $identity->firebird_tb_clave,
                    'firebird_empresa'    => $identity->firebird_empresa,
                    'nombre'              => $fbUser ? trim((string) ($fbUser->NOMBRE ?? '')) : '',
                    'correo'              => $fbUser ? trim((string) ($fbUser->CORREO ?? '')) : null,
                    'photo'               => $fbUser ? trim((string) ($fbUser->PHOTO  ?? '')) : null,
                    'tb'                  => $tbRow ? [
                        'clave'      => $tbRow->CLAVE      ?? null,
                        'nombre'     => $tbRow->NOMBRE     ?? null,
                        'depto'      => $tbRow->DEPTO      ?? null,
                        'puesto'     => $tbRow->PUESTO     ?? null,
                        'fecha_alta' => $tbRow->FECHA_ALTA ?? null,
                    ] : null,
                ];
            })->values();

        return response()->json($result);
    }

    public function create() {}
    public function store(Request $request) {}
    public function show(string $id) {}
    public function edit(string $id) {}
    public function update(Request $request, string $id) {}
    public function destroy(string $id) {}
}