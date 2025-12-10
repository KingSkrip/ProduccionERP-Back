<?php

namespace App\Http\Controllers\SuperAdmin\GestionarUsuarios\Colaborador;

use App\Helpers\ValidationMessages;
use App\Http\Controllers\Controller;
use App\Http\Resources\UsuarioFullResource;
use App\Http\Resources\UsuarioResource;
use App\Models\Direccion;
use App\Models\ModelHasRole;
use App\Models\UserEmpleo;
use App\Models\UserFiscal;
use App\Models\UserNomina;
use App\Models\Users;
use App\Models\UserSeguridadSocial;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ColaboradorController extends Controller
{
    /**
     * Lista los colaboradores (role_clave = 1)
     */
    public function index()
    {
        try {
            $userId = auth()->id();

            $usuarios = Users::with([
                'direccion',
                'departamento',
                'roles',
                'nomina',
                'empleos',
                'fiscal',
                'seguridadSocial'
            ])
                ->whereHas('roles', function ($query) {
                    $query->where('role_clave', 1);
                })
                ->where('id', '!=', $userId)
                ->get();

            return response()->json([
                'message' => 'Colaboradores obtenidos exitosamente',
                'data' => UsuarioFullResource::collection($usuarios)
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en index Colaborador: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener colaboradores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear colaborador con todos los datos relacionados
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validación principal
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:users,correo',
                    'password' => 'required|string|min:6',
                    'usuario' => 'required|string|max:255',
                    'telefono' => 'required|string|max:15|unique:users,telefono',
                    'curp' => 'required|string|max:18|unique:users,curp',
                    'departamento_id' => 'required|exists:departamentos,id',
                    'photo' => 'required|file|image|mimes:jpeg,jpg,png,gif',

                    // JSON obligatorios
                    'direccion' => 'required|json',
                    'empleo' => 'required|json',
                    'fiscal' => 'required|json',
                    'seguridad_social' => 'required|json',
                    'nomina' => 'required|json',
                ],
                ValidationMessages::messages()
            );


            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // 1. Crear Dirección (si existe)
            $direccionId = null;
            if ($request->has('direccion')) {
                $direccionData = json_decode($request->direccion, true);
                if (!empty(array_filter($direccionData))) {
                    $direccion = Direccion::create($direccionData);
                    $direccionId = $direccion->id;
                }
            }

            // 2. Foto
            $photoPath = 'photos/users.jpg';
            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }
                $file = $request->file('photo');
                $filename = 'photo_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);
                $photoPath = 'photos/' . $filename;
            }

            // 3. Crear Usuario
            $usuario = Users::create([
                'nombre' => $request->name,
                'usuario' => $request->input('usuario', 'COL'),
                'correo' => $request->email,
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
                'curp' => $request->curp,
                'departamento_id' => $request->departamento_id,
                'direccion_id' => $direccionId,
                'photo' => $photoPath,
                'status_id' => 1,
            ]);

            // 4. Asignar rol colaborador (1)
            ModelHasRole::create([
                'role_clave' => 1,
                'model_clave' => $usuario->id,
                'model_type' => Users::class,
            ]);

            // 5. Crear Empleo (si existe)
            if ($request->has('empleo')) {
                $empleoData = json_decode($request->empleo, true);
                if (!empty(array_filter($empleoData))) {
                    UserEmpleo::create([
                        'user_id' => $usuario->id,
                        'puesto' => $empleoData['puesto'] ?? null,
                        'fecha_inicio' => $empleoData['fecha_inicio'] ?? null,
                        'fecha_fin' => $empleoData['fecha_fin'] ?? null,
                        'comentarios' => $empleoData['comentarios'] ?? null,
                    ]);
                }
            }

            // 6. Crear Datos Fiscales (si existen)
            if ($request->has('fiscal')) {
                $fiscalData = json_decode($request->fiscal, true);
                if (!empty(array_filter($fiscalData))) {
                    UserFiscal::create([
                        'user_id' => $usuario->id,
                        'rfc' => $fiscalData['rfc'] ?? null,
                        'curp' => $request->curp, // Usamos el CURP del usuario
                        'regimen_fiscal' => $fiscalData['regimen_fiscal'] ?? null,
                    ]);
                }
            }

            // 7. Crear Seguridad Social (si existe)
            if ($request->has('seguridad_social')) {
                $ssData = json_decode($request->seguridad_social, true);
                if (!empty(array_filter($ssData))) {
                    UserSeguridadSocial::create([
                        'user_id' => $usuario->id,
                        'numero_imss' => $ssData['numero_imss'] ?? null,
                        'fecha_alta' => $ssData['fecha_alta'] ?? null,
                        'tipo_seguro' => $ssData['tipo_seguro'] ?? null,
                    ]);
                }
            }

            // 8. Crear Nómina (si existe)
            if ($request->has('nomina')) {
                $nominaData = json_decode($request->nomina, true);
                if (!empty(array_filter($nominaData))) {
                    UserNomina::create([
                        'user_id' => $usuario->id,
                        'numero_tarjeta' => $nominaData['numero_tarjeta'] ?? null,
                        'banco' => $nominaData['banco'] ?? null,
                        'clabe_interbancaria' => $nominaData['clabe_interbancaria'] ?? null,
                        'salario_base' => $nominaData['salario_base'] ?? null,
                        'frecuencia_pago' => $nominaData['frecuencia_pago'] ?? null,
                    ]);
                }
            }

            DB::commit();

            // Cargar relaciones para la respuesta
            $usuario->load([
                'direccion',
                'departamento',
                'roles',
                'nomina',
                'empleos',
                'fiscal',
                'seguridadSocial'
            ]);

            return response()->json([
                'message' => 'Colaborador creado exitosamente',
                'user' => new UsuarioResource($usuario)
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en store Colaborador: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al crear colaborador',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Editar colaborador
     */
    public function edit($id)
    {
        try {
            $usuario = Users::with([
                'direccion',
                'departamento',
                'roles',
                'nomina',
                'empleos',
                'fiscal',
                'seguridadSocial'
            ])->findOrFail($id);

            return response()->json([
                'message' => 'Datos obtenidos',
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en edit Colaborador: ' . $e->getMessage());

            return response()->json([
                'message' => 'Colaborador no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualizar colaborador con todos los datos relacionados
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            // Validación
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:users,correo,' . $id . ',id',
                    'telefono' => 'nullable|string|max:15',
                    'curp' => 'nullable|string|max:18',
                    'departamento_id' => 'nullable|exists:departamentos,id',
                    'usuario' => 'nullable|string|max:255',
                    'current_password' => 'required_with:password|string',
                    'password' => 'nullable|string|min:6',
                    'photo' => 'nullable',

                    'direccion' => 'nullable|json',
                    'empleo' => 'nullable|json',
                    'fiscal' => 'nullable|json',
                    'seguridad_social' => 'nullable|json',
                    'nomina' => 'nullable|json',
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $usuario = Users::findOrFail($id);

            // 1. Actualizar/Crear Dirección
            if ($request->has('direccion')) {
                $direccionData = json_decode($request->direccion, true);
                if (!empty(array_filter($direccionData))) {
                    if ($usuario->direccion_id) {
                        Direccion::where('id', $usuario->direccion_id)->update($direccionData);
                    } else {
                        $direccion = Direccion::create($direccionData);
                        $usuario->direccion_id = $direccion->id;
                    }
                }
            }

            // 2. Foto
            $photoPath = $usuario->photo;
            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }
                $file = $request->file('photo');
                $filename = 'photo_' . $id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);
                $photoPath = 'photos/' . $filename;

                // Eliminar foto anterior
                $defaultPhoto = 'photos/users.jpg';
                if ($usuario->photo && $usuario->photo !== $defaultPhoto) {
                    $oldPhoto = public_path($usuario->photo);
                    if (file_exists($oldPhoto)) {
                        @unlink($oldPhoto);
                    }
                }
            }

            // 3. Cambio de contraseña
            if ($request->filled('password')) {
                $loggedUser = auth()->user();
                if (!Hash::check($request->current_password, $loggedUser->password)) {
                    DB::rollBack();
                    return response()->json(['message' => 'Contraseña actual incorrecta'], 403);
                }
                $usuario->password = Hash::make($request->password);
            }

            // 4. Actualizar datos básicos del usuario
            $usuario->update([
                'nombre' => $request->name,
                'correo' => $request->email,
                'telefono' => $request->telefono,
                'curp' => $request->curp,
                'departamento_id' => $request->departamento_id ?? $usuario->departamento_id,
                'usuario' => $request->usuario ?? $usuario->usuario,
                'photo' => $photoPath,
            ]);

            // 5. Actualizar/Crear Empleo
            if ($request->has('empleo')) {
                $empleoData = json_decode($request->empleo, true);
                if (!empty(array_filter($empleoData))) {
                    UserEmpleo::updateOrCreate(
                        ['user_id' => $usuario->id],
                        $empleoData
                    );
                }
            }

            // 6. Actualizar/Crear Datos Fiscales
            if ($request->has('fiscal')) {
                $fiscalData = json_decode($request->fiscal, true);
                if (!empty(array_filter($fiscalData))) {
                    $fiscalData['curp'] = $request->curp; // Sincronizar CURP
                    UserFiscal::updateOrCreate(
                        ['user_id' => $usuario->id],
                        $fiscalData
                    );
                }
            }

            // 7. Actualizar/Crear Seguridad Social
            if ($request->has('seguridad_social')) {
                $ssData = json_decode($request->seguridad_social, true);
                if (!empty(array_filter($ssData))) {
                    UserSeguridadSocial::updateOrCreate(
                        ['user_id' => $usuario->id],
                        $ssData
                    );
                }
            }

            // 8. Actualizar/Crear Nómina
            if ($request->has('nomina')) {
                $nominaData = json_decode($request->nomina, true);
                if (!empty(array_filter($nominaData))) {
                    UserNomina::updateOrCreate(
                        ['user_id' => $usuario->id],
                        $nominaData
                    );
                }
            }

            DB::commit();

            // Cargar relaciones para la respuesta
            $usuario->load([
                'direccion',
                'departamento',
                'roles',
                'nomina',
                'empleos',
                'fiscal',
                'seguridadSocial'
            ]);

            return response()->json([
                'message' => 'Colaborador actualizado correctamente',
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en update Colaborador: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'message' => 'Error al actualizar colaborador',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar colaborador
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $loggedUserId = auth()->id();

            if ((int)$loggedUserId === (int)$id) {
                return response()->json([
                    'message' => 'No puedes eliminar tu propio usuario.'
                ], 403);
            }

            $usuario = Users::with(['direccion'])->findOrFail($id);

            // Eliminar roles
            ModelHasRole::where('model_clave', $id)
                ->where('model_type', Users::class)
                ->delete();

            // Eliminar datos relacionados
            UserEmpleo::where('user_id', $id)->delete();
            UserFiscal::where('user_id', $id)->delete();
            UserSeguridadSocial::where('user_id', $id)->delete();
            UserNomina::where('user_id', $id)->delete();

            // Eliminar dirección
            if ($usuario->direccion_id) {
                Direccion::where('id', $usuario->direccion_id)->delete();
            }

            // Eliminar foto
            $defaultPhoto = 'photos/users.jpg';
            if ($usuario->photo && $usuario->photo !== $defaultPhoto) {
                $photo = public_path($usuario->photo);
                if (file_exists($photo)) {
                    @unlink($photo);
                }
            }

            // Eliminar usuario
            $usuario->delete();

            DB::commit();

            return response()->json([
                'message' => 'Colaborador eliminado correctamente'
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Colaborador no encontrado',
                'error' => $e->getMessage()
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en destroy Colaborador: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al eliminar colaborador',
                'error' => $e->getMessage()
            ], 500);
        }
    }














    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status_id' => 'required|in:1,2', // Asegura que solo valores válidos
        ]);

        $usuario = Users::find($id);

        if (!$usuario) {
            return response()->json([
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $usuario->status_id = $request->status_id;
        $usuario->save();

        return response()->json([
            'message' => 'Status actualizado correctamente',
            'usuario' => $usuario
        ]);
    }
}
