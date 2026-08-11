<?php

namespace App\Http\Controllers\SuperAdmin\GestionarUsuarios\Colaborador;

use App\Http\Controllers\Controller;
use App\Http\Resources\UsuarioResource;
use App\Models\Firebird\Users;
use App\Models\ModelHasRole;
use App\Models\UserFirebirdIdentity;
use App\Models\UserPuesto;
use App\Models\UserTurno;
use App\Services\FirebirdEmpresaManualService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ColaboradorController extends Controller
{
    /**
     * ROLE_CLAVE que identifica a "colaboradores" en la tabla roles (ver dump: id=1 'COLABORADOR')
     * ⚠️ CONFIRMAR: si el filtro de "colaboradores" debe ser este id fijo o debe venir por request.
     */
    private const ROLE_COLABORADOR = 1;

    /**
     * Lista colaboradores (identidades MySQL con rol COLABORADOR) + overlay de datos Firebird (USUARIOS/TB/DEPTO/PUESTO)
     */
    // public function index(Request $request)
    // {
    //     try {
    //         $perPage = (int) $request->query('per_page', 20);

    //         $identities = UserFirebirdIdentity::whereHas('roles', function ($q) {
    //             $q->where('role_id', self::ROLE_COLABORADOR);
    //         })
    //             ->with([
    //                 'roles.role',
    //                 'roles.subrol',
    //                 'puestoActivo.puesto',
    //                 'puestoActivo.area',
    //                 'puestoActivo.jefe',
    //                 'turnoActivo.turno.turnoDias',
    //                 'turnoActivo.status',
    //             ])
    //             ->paginate($perPage);

    //         $payload = $this->overlayFirebirdData(collect($identities->items()));

    //         return response()->json([
    //             'message' => 'Colaboradores obtenidos exitosamente',
    //             'data' => $payload,
    //             'meta' => [
    //                 'current_page' => $identities->currentPage(),
    //                 'last_page' => $identities->lastPage(),
    //                 'total' => $identities->total(),
    //                 'per_page' => $identities->perPage(),
    //             ],
    //         ], 200);
    //     } catch (Exception $e) {
    //         Log::error('Error en index ColaboradorController: '.$e->getMessage());

    //         return response()->json([
    //             'message' => 'Error al obtener colaboradores',
    //             'error' => $e->getMessage(),
    //         ], 500);
    //     }
    // }


    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->query('per_page', 20);

            $identities = UserFirebirdIdentity::whereHas('roles', function ($q) {
                $q->where('role_id', self::ROLE_COLABORADOR);
            })
                ->with([
                    'roles.role',
                    'roles.subrol',
                    'puestoActivo.puesto',
                    'puestoActivo.area',
                    'puestoActivo.jefe',
                    'turnoActivo.turno.turnoDias',
                    'turnoActivo.status',
                ])
                ->paginate($perPage);

            $payload = $this->overlayFirebirdData(collect($identities->items()));

            return response()->json([
                'message' => 'Colaboradores obtenidos exitosamente',
                'data' => $payload,
                'jefes' => $this->buildPosiblesJefes(), // 👈 nuevo
                'meta' => [
                    'current_page' => $identities->currentPage(),
                    'last_page' => $identities->lastPage(),
                    'total' => $identities->total(),
                    'per_page' => $identities->perPage(),
                ],
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en index ColaboradorController: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al obtener colaboradores',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Catálogo de posibles jefes / jefes auxiliares — TODAS las identidades,
     * sin filtrar por rol COLABORADOR (un jefe puede no ser "colaborador").
     */
    private function buildPosiblesJefes(): Collection
    {
        $identities = UserFirebirdIdentity::all();

        $firebirdIds = $identities->pluck('firebird_user_clave')->filter()->unique()->values();
        $firebirdUsersById = Users::whereIn('ID', $firebirdIds)->get()->keyBy('ID');

        return $identities->map(function (UserFirebirdIdentity $identity) use ($firebirdUsersById) {
            $firebirdUser = $firebirdUsersById->get($identity->firebird_user_clave);

            if (! $firebirdUser) {
                return null;
            }

            return [
                'id' => $identity->id,
                'name' => $firebirdUser->NOMBRE ? trim((string) $firebirdUser->NOMBRE) : null,
            ];
        })->filter()->values();
    }
    
    /**
     * Ver un colaborador (por id de users_firebird_identities)
     */
    public function edit($id)
    {
        try {
            $identity = UserFirebirdIdentity::with([
                'roles.role',
                'roles.subrol',
                'puestoActivo.puesto',
                'puestoActivo.area',
                'puestoActivo.jefe',
                // 🔧 TODO: mismo comentario que en index() — activar si existe la relación
                // 'turnoActivo.turno.turnoDias',
                // 'turnoActivo.status',
            ])->findOrFail($id);

            $payload = $this->overlayFirebirdData(collect([$identity]))->first();

            return response()->json([
                'message' => 'Datos obtenidos',
                'user' => $payload,
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en edit ColaboradorController: '.$e->getMessage());

            return response()->json([
                'message' => 'Colaborador no encontrado',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Catálogo de TB disponibles (empleados NOI) para enlazar a un usuario nuevo.
     * Filtra los que ya están enlazados en users_firebird_identities para esa empresa.
     *
     * GET /colaborador/tb-disponibles?empresa=04&fecha=2026-08-10
     */
    public function tbDisponibles(Request $request)
    {
        $request->validate([
            'empresa' => 'required|string|size:2',
        ]);

        try {
            $empresa = $request->query('empresa');
            $fecha = $request->query('fecha');

            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tbRows = $firebirdNoi->getOperationalTable('TB', $fecha);

            $yaEnlazados = UserFirebirdIdentity::where('firebird_empresa', $empresa)
                ->whereNotNull('firebird_tb_clave')
                ->pluck('firebird_tb_clave')
                ->map(fn ($v) => (string) $v)
                ->all();

            $disponibles = $tbRows
                ->reject(fn ($row) => in_array((string) trim((string) $row->CLAVE), $yaEnlazados))
                ->map(fn ($row) => [
                    'clave' => trim((string) $row->CLAVE),
                    'nombre' => $row->NOMBRE ?? null,
                    'depto' => $row->DEPTO ?? null,
                    'puesto' => $row->PUESTO ?? null,
                    // ⚠️ CONFIRMAR nombre de tabla TB real usada por identity (ej. TB19072604) para guardar en firebird_tb_tabla
                ])
                ->values();

            return response()->json([
                'message' => 'Catálogo TB obtenido',
                'data' => $disponibles,
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en tbDisponibles: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al obtener catálogo TB',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Crear colaborador de punta a punta:
     * 1) USUARIOS (Firebird)
     * 2) users_firebird_identities (MySQL) — con o sin TB enlazado
     * 3) model_has_roles (rol/subrol)
     * 4) user_puestos (opcional)
     * 5) user_turnos (opcional)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'usuario' => 'required|string|max:255',
            'password' => 'required|string|min:6',
            'photo' => 'nullable|file|image|mimes:jpeg,jpg,png,gif',

            // Enlace opcional a TB existente (alta normal). Si viene, los 3 son requeridos juntos.
            'empresa' => 'nullable|string|size:2|required_with:tb_clave,tb_tabla',
            'tb_clave' => 'nullable|string|required_with:empresa,tb_tabla',
            'tb_tabla' => 'nullable|string|required_with:empresa,tb_clave',

            // Rol / subrol
            'role_id' => 'required|exists:roles,id',
            'subrol_id' => 'nullable|exists:subroles,id',

            // Puesto (opcional)
            'puesto_id' => 'nullable|exists:puestos,id',
            'area_id' => 'nullable|exists:areas,id',
            'jefe_id' => 'nullable|exists:users_firebird_identities,id',
            'jefe_aux_id' => 'nullable|exists:users_firebird_identities,id',
            'fecha_inicio_puesto' => 'nullable|date',

            // Turno (opcional)
            'turno_id' => 'nullable|exists:turnos,id',

            'excluir_checador' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        // --- Validar que el TB elegido no esté ya enlazado a otra identidad ---
        if ($request->filled('tb_clave')) {
            $yaUsado = UserFirebirdIdentity::where('firebird_empresa', $request->empresa)
                ->where('firebird_tb_clave', $request->tb_clave)
                ->exists();

            if ($yaUsado) {
                return response()->json([
                    'message' => 'Ese registro de TB ya está enlazado a otro usuario',
                ], 422);
            }
        }

        // --- 1) Foto (si viene) ---
        $photoPath = 'photos/users.jpg';
        if ($request->hasFile('photo')) {
            if (! file_exists(public_path('photos'))) {
                mkdir(public_path('photos'), 0777, true);
            }
            $file = $request->file('photo');
            $filename = 'photo_'.time().'.'.$file->getClientOriginalExtension();
            $file->move(public_path('photos'), $filename);
            $photoPath = 'photos/'.$filename;
        }

        // --- 2) Alta en Firebird (USUARIOS) — FUERA de la transacción MySQL ---
        try {
            $firebirdUser = Users::create([
                'NOMBRE' => $request->name,
                'CORREO' => $request->email,
                'USUARIO' => $request->usuario,
                'PASSWORD2' => $request->password, // el mutator hace bcrypt() automáticamente
                'PHOTO' => $photoPath,
                'STATUS' => 1, // ⚠️ CONFIRMAR formato real (1/2 vs 'A'/'B') en Firebird USUARIOS.STATUS
            ]);
        } catch (Exception $e) {
            Log::error('Error creando USUARIOS en Firebird: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al crear el usuario en Firebird',
                'error' => $e->getMessage(),
            ], 500);
        }

        // --- 3) Todo lo demás en MySQL, con rollback manual del paso Firebird si falla ---
        DB::beginTransaction();

        try {
            $identity = UserFirebirdIdentity::create([
                'firebird_user_clave' => $firebirdUser->ID,
                'firebird_tb_clave' => $request->tb_clave,
                'firebird_tb_tabla' => $request->tb_tabla,
                'firebird_empresa' => $request->empresa,
                'excluir_checador' => $request->boolean('excluir_checador', false),
            ]);

            ModelHasRole::create([
                'role_id' => $request->role_id,
                'subrol_id' => $request->subrol_id,
                'firebird_identity_id' => $identity->id,
                'model_type' => 'firebird_identity',
            ]);

            if ($request->filled('puesto_id')) {
                UserPuesto::updateOrCreate(
                    ['user_firebird_identity_id' => $identity->id],
                    [
                        'puesto_id' => $request->puesto_id,
                        'area_id' => $request->area_id,
                        'jefe_id' => $request->jefe_id,
                        'jefe_aux_id' => $request->jefe_aux_id,
                        'fecha_inicio' => $request->fecha_inicio_puesto ?? now()->toDateString(),
                        'activo' => true,
                    ]
                );
            }

            if ($request->filled('turno_id')) {
                UserTurno::updateOrCreate(
                    ['user_firebird_identity_id' => $identity->id, 'status_id' => 1],
                    ['turno_id' => $request->turno_id]
                );
            }

            DB::commit();

            $identity->load(['roles.role', 'roles.subrol', 'puestoActivo.puesto', 'puestoActivo.area']);
            $payload = $this->overlayFirebirdData(collect([$identity]))->first();

            return response()->json([
                'message' => 'Colaborador creado exitosamente',
                'user' => $payload,
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();

            // Rollback manual del insert en Firebird, porque no comparte transacción con MySQL
            try {
                $firebirdUser->delete();
            } catch (Exception $cleanupError) {
                Log::error('No se pudo revertir el usuario creado en Firebird tras fallo en MySQL', [
                    'firebird_id' => $firebirdUser->ID,
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            Log::error('Error en store ColaboradorController (MySQL): '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al crear colaborador',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar colaborador: datos básicos en Firebird + rol/subrol/puesto/turno en MySQL
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'usuario' => 'nullable|string|max:255',
            'password' => 'nullable|string|min:6',
            'current_password' => 'required_with:password|string',
            'photo' => 'nullable|file|image|mimes:jpeg,jpg,png,gif',

            'role_id' => 'nullable|exists:roles,id',
            'subrol_id' => 'nullable|exists:subroles,id',

            'puesto_id' => 'nullable|exists:puestos,id',
            'area_id' => 'nullable|exists:areas,id',
            'jefe_id' => 'nullable|exists:users_firebird_identities,id',
            'jefe_aux_id' => 'nullable|exists:users_firebird_identities,id',

            'turno_id' => 'nullable|exists:turnos,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Datos inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $identity = UserFirebirdIdentity::findOrFail($id);
            $firebirdUser = Users::findOrFail($identity->firebird_user_clave);
        } catch (Exception $e) {
            return response()->json(['message' => 'Colaborador no encontrado'], 404);
        }

        // --- Cambio de password (requiere validar contra la del usuario logueado, igual que antes) ---
        if ($request->filled('password')) {
            $loggedFirebirdUser = Users::find(auth()->id());
            if (! $loggedFirebirdUser || ! \Illuminate\Support\Facades\Hash::check($request->current_password, $loggedFirebirdUser->PASSWORD2)) {
                return response()->json(['message' => 'Contraseña actual incorrecta'], 403);
            }
        }

        // --- Foto ---
        $photoPath = $firebirdUser->PHOTO;
        if ($request->hasFile('photo')) {
            if (! file_exists(public_path('photos'))) {
                mkdir(public_path('photos'), 0777, true);
            }
            $file = $request->file('photo');
            $filename = 'photo_'.$id.'_'.time().'.'.$file->getClientOriginalExtension();
            $file->move(public_path('photos'), $filename);
            $photoPath = 'photos/'.$filename;

            $defaultPhoto = 'photos/users.jpg';
            if ($firebirdUser->PHOTO && $firebirdUser->PHOTO !== $defaultPhoto) {
                $old = public_path($firebirdUser->PHOTO);
                if (file_exists($old)) {
                    @unlink($old);
                }
            }
        }

        try {
            // 1) Firebird: datos básicos (fuera de transacción MySQL, no hay forma de revertir en cross-db,
            //    así que este paso va primero y si falla no tocamos MySQL)
            $firebirdUser->update(array_filter([
                'NOMBRE' => $request->name,
                'CORREO' => $request->email,
                'USUARIO' => $request->usuario,
                'PHOTO' => $photoPath,
                'PASSWORD2' => $request->filled('password') ? $request->password : null,
            ], fn ($v) => ! is_null($v)));

            DB::beginTransaction();

            if ($request->filled('role_id')) {
                ModelHasRole::updateOrCreate(
                    ['firebird_identity_id' => $identity->id],
                    ['role_id' => $request->role_id, 'subrol_id' => $request->subrol_id, 'model_type' => 'firebird_identity']
                );
            }

            if ($request->filled('puesto_id')) {
                UserPuesto::updateOrCreate(
                    ['user_firebird_identity_id' => $identity->id],
                    [
                        'puesto_id' => $request->puesto_id,
                        'area_id' => $request->area_id,
                        'jefe_id' => $request->jefe_id,
                        'jefe_aux_id' => $request->jefe_aux_id,
                        'activo' => true,
                    ]
                );
            }

            if ($request->filled('turno_id')) {
                UserTurno::updateOrCreate(
                    ['user_firebird_identity_id' => $identity->id, 'status_id' => 1],
                    ['turno_id' => $request->turno_id]
                );
            }

            DB::commit();

            $identity->load(['roles.role', 'roles.subrol', 'puestoActivo.puesto', 'puestoActivo.area']);
            $payload = $this->overlayFirebirdData(collect([$identity]))->first();

            return response()->json([
                'message' => 'Colaborador actualizado correctamente',
                'user' => $payload,
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en update ColaboradorController: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al actualizar colaborador',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar status (activo/inactivo).
     * ⚠️ CONFIRMAR: formato de USUARIOS.STATUS en Firebird (aquí asumo 1=activo, 2=inactivo).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status_id' => 'required|in:1,2',
        ]);

        try {
            $identity = UserFirebirdIdentity::findOrFail($id);
            $firebirdUser = Users::findOrFail($identity->firebird_user_clave);

            $firebirdUser->STATUS = $request->status_id;
            $firebirdUser->save();

            return response()->json([
                'message' => 'Status actualizado correctamente',
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en updateStatus ColaboradorController: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al actualizar status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar colaborador: quita rol/puesto/turno/identity en MySQL.
     * No borra el registro histórico de USUARIOS en Firebird (solo lo desvincula) —
     * ⚠️ CONFIRMAR si en tu caso sí se debe borrar/dar de baja también en Firebird.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $identity = UserFirebirdIdentity::findOrFail($id);

            ModelHasRole::where('firebird_identity_id', $identity->id)->delete();
            UserPuesto::where('user_firebird_identity_id', $identity->id)->delete();
            UserTurno::where('user_firebird_identity_id', $identity->id)->delete();
            $identity->delete();

            DB::commit();

            return response()->json(['message' => 'Colaborador eliminado correctamente'], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en destroy ColaboradorController: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al eliminar colaborador',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Toma una colección de UserFirebirdIdentity (ya con roles/puestoActivo cargados)
     * y le pega encima los datos de Firebird: USUARIOS + TB + DEPTOS + PUESTOS,
     * agrupando las consultas por empresa para no golpear Firebird registro por registro.
     */
    private function overlayFirebirdData(Collection $identities): Collection
    {
        // 1) Traer todos los USUARIOS (Firebird) de una sola pasada
        $firebirdIds = $identities->pluck('firebird_user_clave')->filter()->unique()->values();
        $firebirdUsersById = Users::whereIn('ID', $firebirdIds)->get()->keyBy('ID');

        // 2) Agrupar por empresa para reusar la conexión dinámica de Firebird NOI
        $porEmpresa = $identities->filter(fn ($i) => $i->firebird_empresa && $i->firebird_tb_clave)
            ->groupBy('firebird_empresa');

        $tbByEmpresaClave = [];
        $deptoByEmpresa = [];
        $puestoByEmpresa = [];

        foreach ($porEmpresa as $empresa => $grupo) {
            try {
                $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');

                $tbRows = $firebirdNoi->getOperationalTable('TB');
                $tbByEmpresaClave[$empresa] = $tbRows->keyBy(fn ($row) => trim((string) $row->CLAVE));

                $deptoByEmpresa[$empresa] = $firebirdNoi->getMasterTable('DEPTOS')->keyBy(fn ($row) => trim((string) $row->CLAVE));
                $puestoByEmpresa[$empresa] = $firebirdNoi->getMasterTable('PUESTOS')->keyBy(fn ($row) => trim((string) $row->CLAVE));
            } catch (Exception $e) {
                Log::error('Error consultando Firebird NOI para overlay', [
                    'empresa' => $empresa,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3) Armar el payload final por identidad
        return $identities->map(function (UserFirebirdIdentity $identity) use (
            $firebirdUsersById,
            $tbByEmpresaClave,
            $deptoByEmpresa,
            $puestoByEmpresa
        ) {
            $firebirdUser = $firebirdUsersById->get($identity->firebird_user_clave);

            $tbRow = null;
            $deptoRow = null;
            $puestoRow = null;

            if ($identity->firebird_empresa && $identity->firebird_tb_clave) {
                $claveNorm = trim((string) $identity->firebird_tb_clave);
                $tbRow = $tbByEmpresaClave[$identity->firebird_empresa][$claveNorm] ?? null;

                if ($tbRow) {
                    $deptoClave = isset($tbRow->DEPTO) ? trim((string) $tbRow->DEPTO) : null;
                    $puestoClave = isset($tbRow->PUESTO) ? trim((string) $tbRow->PUESTO) : null;

                    $deptoRow = $deptoClave ? ($deptoByEmpresa[$identity->firebird_empresa][$deptoClave] ?? null) : null;
                    $puestoRow = $puestoClave ? ($puestoByEmpresa[$identity->firebird_empresa][$puestoClave] ?? null) : null;
                }
            }

            if (! $firebirdUser) {
                Log::warning('Identity sin USUARIOS correspondiente en Firebird', [
                    'identity_id' => $identity->id,
                    'firebird_user_clave' => $identity->firebird_user_clave,
                ]);

                return [
                    'identity_id' => $identity->id,
                    'firebird_user_clave' => $identity->firebird_user_clave,
                    'error' => 'usuario_firebird_no_encontrado',
                ];
            }

            $resource = (new UsuarioResource($firebirdUser, [
                'identity_id' => $identity->id,
                'roles' => $identity->roles ?? collect(),
                'user_puesto' => $identity->puestoActivo ?? null,
                'firebird_tb_clave' => $identity->firebird_tb_clave,
                'firebird_empresa' => $identity->firebird_empresa,
                'TB' => $tbRow,
                'DEPTO_NOI' => $deptoRow,
                'PUESTO_NOI' => $puestoRow,
                'turnoActivo' => $identity->turnoActivo ?? null,
            ]))->resolve();

            // ============================================================
            // 🔧 PARCHE LOCAL — NO TOCAR UsuarioResource.php (lo usan otros
            // controladores y romperíamos ese contrato compartido).
            //
            // UsuarioResource solo expone nombres anidados:
            //   USER_PUESTO.PUESTO.NOMBRE / AREA.NOMBRE / JEFE.NOMBRE
            // pero el formulario de Angular (colaboradorlist.component.ts)
            // necesita los IDs crudos para preseleccionar los <mat-select>
            // de Puesto / Área / Jefe / Jefe auxiliar:
            //   puestoActivo?.puesto_id, puestoActivo?.area_id, puestoActivo?.jefe_id
            //
            // Los inyectamos aquí, DESPUÉS de resolve(), solo para el payload
            // que devuelve ColaboradorController. Esto no afecta a ningún
            // otro controlador que use UsuarioResource.
            // ============================================================
            if ($identity->puestoActivo && isset($resource['USER_PUESTO'])) {
                $resource['USER_PUESTO']['puesto_id'] = $identity->puestoActivo->puesto_id;
                $resource['USER_PUESTO']['area_id'] = $identity->puestoActivo->area_id;
                $resource['USER_PUESTO']['jefe_id'] = $identity->puestoActivo->jefe_id;
                $resource['USER_PUESTO']['jefe_aux_id'] = $identity->puestoActivo->jefe_aux_id;
            }

            // 🔧 TODO turno: activar junto con las líneas comentadas de arriba
            // una vez confirmada la relación turnoActivo() en el modelo.
            if ($identity->turnoActivo && isset($resource['TURNO_ASIGNADO'])) {
                $resource['TURNO_ASIGNADO']['turno_id'] = $identity->turnoActivo->turno_id;
            }

            return $resource;
        });
    }

    public function catalogoJefes()
    {
        $identities = UserFirebirdIdentity::with(['puestoActivo'])->get();
        $firebirdIds = $identities->pluck('firebird_user_clave')->filter()->unique();
        $firebirdUsers = Users::whereIn('ID', $firebirdIds)->get()->keyBy('ID');

        $data = $identities->map(function ($identity) use ($firebirdUsers) {
            $fu = $firebirdUsers->get($identity->firebird_user_clave);

            return [
                'id' => $identity->id,
                'name' => $fu?->NOMBRE ? trim($fu->NOMBRE) : null,
            ];
        })->filter(fn ($u) => $u['name']);

        return response()->json(['message' => 'Catálogo de jefes', 'data' => $data->values()]);
    }


    /**
     * Catálogo de posibles jefes / jefes auxiliares.
     * A diferencia de index(), NO filtra por rol COLABORADOR — cualquier
     * identidad puede ser jefe (admins, RH, etc.), así que se traen todas.
     */
public function posiblesJefes()
    {
        try {
            return response()->json([
                'message' => 'Catálogo de jefes obtenido',
                'data' => $this->buildPosiblesJefes(),
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en posiblesJefes ColaboradorController: '.$e->getMessage());

            return response()->json([
                'message' => 'Error al obtener catálogo de jefes',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}