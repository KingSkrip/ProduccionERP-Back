<?php

namespace App\Http\Controllers\SuperAdmin\GestionarUsuarios\Colaborador;

use App\Helpers\ValidationMessages;
use App\Http\Controllers\Controller;

use App\Http\Resources\UsuarioResource;
use App\Models\ModelHasRole;
use App\Models\Users;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ColaboradorController extends Controller
{
    /**
     * Muestra la lista de usuarios RH (usuarios con role_clave = 2).
     * 🔥 CORRECCIÓN: Adaptado a nueva estructura MySQL
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        try {
            $userId = auth()->id(); // ← Usar auth()->id() en lugar de auth()->user()->CLAVE

            // Traer usuarios con role_clave = 2 excepto el usuario autenticado
            $usuarios = Users::whereHas('roles', function ($query) {
                $query->where('role_clave', 1);
            })
                ->where('id', '!=', $userId)
                ->get();

            return response()->json([
                'message' => 'Usuarios obtenidos exitosamente',
                'data' => UsuarioResource::collection($usuarios)
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en index RH: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al obtener usuarios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retorna los datos necesarios para crear un usuario RH
     */
    public function create()
    {
        return response()->json([
            'message' => 'Formulario de creación de usuario RH',
            'data' => []
        ], 200);
    }

    /**
     * Almacena un nuevo usuario RH en la base de datos.
     * 🔥 CORRECCIÓN: Adaptado a nueva estructura MySQL
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // Validar datos
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:users,correo',
                    'password' => 'required|string|min:6',
                    'usuario' => 'nullable|string|max:255',
                    'departamento_id' => 'nullable|exists:departamentos,id',
                    'photo' => 'nullable|file|image|mimes:jpeg,jpg,png,gif'
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ----------------------------------------
            // 📌 MANEJO DE FOTO
            // ----------------------------------------
            $photoPath = 'photos/users.jpg'; // Foto por defecto

            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }

                $file = $request->file('photo');
                $tempId = time();
                $filename = 'photo_' . $tempId . '_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);
                $photoPath = 'photos/' . $filename;
            }

            // ----------------------------------------
            // 📌 CREAR USUARIO
            // ----------------------------------------
            $usuario = Users::create([
                'nombre' => $request->name,
                'usuario' => $request->input('usuario', 'RH'),
                'correo' => $request->email,
                'password' => Hash::make($request->password),
                'departamento_id' => $request->departamento_id,
                'photo' => $photoPath,
                'status_id' => 1, // Activo por defecto
            ]);

            // ----------------------------------------
            // 📌 ASIGNAR ROL RH (role_clave = 2)
            // ----------------------------------------
            ModelHasRole::create([
                'role_clave' => 1, // ROL RH
                'model_clave' => $usuario->id,
                'model_type' => Users::class,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Usuario RH creado exitosamente',
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nombre,
                    'email' => $usuario->correo,
                    'usuario' => $usuario->usuario,
                    'departamento_id' => $usuario->departamento_id,
                    'photo' => $usuario->photo,
                ]
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en store RH: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al crear usuario RH',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Obtiene los datos de un usuario RH para editar
     * 🔥 CORRECCIÓN: Adaptado a nueva estructura
     */
    public function edit($id)
    {
        try {
            $usuario = Users::findOrFail($id);

            return response()->json([
                'message' => 'Datos obtenidos',
                'user' => [
                    'name' => $usuario->nombre,
                    'email' => $usuario->correo,
                    'usuario' => $usuario->usuario,
                    'photo' => $usuario->photo,
                    'departamento_id' => $usuario->departamento_id,
                ]
            ], 200);
        } catch (Exception $e) {
            Log::error('Error en edit RH: ' . $e->getMessage());
            return response()->json([
                'message' => 'Usuario no encontrado',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Actualiza un usuario RH en la base de datos.
     * 🔥 CORRECCIÓN: Adaptado a nueva estructura MySQL
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
                    'departamento_id' => 'nullable|exists:departamentos,id',
                    'usuario' => 'nullable|string|max:255',
                    'current_password' => 'required_with:password|string',
                    'password' => 'nullable|string|min:6',
                    'photo' => 'nullable',
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validar foto si se envía
            if ($request->hasFile('photo')) {
                $validator = Validator::make(
                    $request->all(),
                    ['photo' => 'file|image|mimes:jpeg,jpg,png,gif'],
                    ValidationMessages::messages()
                );

                if ($validator->fails()) {
                    return response()->json([
                        'message' => 'Datos inválidos',
                        'errors' => $validator->errors()
                    ], 422);
                }
            }

            $usuario = Users::findOrFail($id);

            // ✔️ MANTENER FOTO ACTUAL
            $photoPath = $usuario->photo;

            // ✔️ SI SE ENVÍA UNA NUEVA FOTO
            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }

                $file = $request->file('photo');
                $filename = 'photo_' . $id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);

                $photoPath = 'photos/' . $filename;

                // Eliminar foto anterior si no es la default
                $defaultPhoto = 'photos/users.jpg';
                if ($usuario->photo && $usuario->photo !== $defaultPhoto) {
                    $oldPhotoPath = public_path($usuario->photo);
                    if (file_exists($oldPhotoPath)) {
                        @unlink($oldPhotoPath);
                    }
                }
            }

            // ✔️ VALIDAR CONTRASEÑA
            if ($request->filled('password')) {
                $loggedUser = auth()->user();

                if (!Hash::check($request->current_password, $loggedUser->password)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Contraseña actual incorrecta',
                    ], 403);
                }

                $usuario->password = Hash::make($request->password);
            }

            // ✔️ ACTUALIZAR CAMPOS
            $usuario->update([
                'nombre' => $request->name,
                'correo' => $request->email,
                'departamento_id' => $request->departamento_id ?? $usuario->departamento_id,
                'usuario' => $request->usuario ?? $usuario->usuario,
                'photo' => $photoPath,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Usuario RH actualizado correctamente',
                'user' => [
                    'id' => $usuario->id,
                    'name' => $usuario->nombre,
                    'email' => $usuario->correo,
                    'departamento_id' => $usuario->departamento_id,
                    'usuario' => $usuario->usuario,
                    'photo' => $usuario->photo,
                ]
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en update RH: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al actualizar usuario RH',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Elimina un usuario RH de la base de datos.
     * 🔥 CORRECCIÓN: Adaptado a nueva estructura MySQL
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

            $usuario = Users::findOrFail($id);

            $responseUser = [
                'id' => $usuario->id,
                'name' => $usuario->nombre,
                'email' => $usuario->correo,
                'photo' => $usuario->photo ?? null,
            ];

            // Eliminar roles asociados
            ModelHasRole::where('model_clave', $id)
                ->where('model_type', Users::class)
                ->delete();

            // Eliminar foto si no es la default
            $defaultPhoto = 'photos/users.jpg';
            if ($usuario->photo && $usuario->photo !== $defaultPhoto) {
                $photoPath = public_path($usuario->photo);
                if (file_exists($photoPath) && is_writable($photoPath)) {
                    @unlink($photoPath);
                }
            }

            // Eliminar usuario
            $usuario->delete();

            DB::commit();

            return response()->json([
                'message' => 'Usuario RH eliminado correctamente',
                'user' => $responseUser
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            Log::warning('Intento de eliminar usuario no encontrado: ' . $e->getMessage());
            return response()->json([
                'message' => 'Usuario no encontrado',
                'error' => $e->getMessage()
            ], 404);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en destroy RH: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al eliminar usuario RH',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}