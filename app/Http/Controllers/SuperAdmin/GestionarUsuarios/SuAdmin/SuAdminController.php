<?php

namespace App\Http\Controllers\SuperAdmin\GestionarUsuarios\SuAdmin;

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

class SuAdminController extends Controller
{
    /**
     * Listado de usuarios con role_clave = 3 (RH)
     */
    public function index()
    {
        try {
            $userId = auth()->id();

            $usuarios = Users::whereHas('roles', function ($query) {
                $query->where('role_clave', 3);
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
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Formulario vacío para crear nuevo RH
     */
    public function create()
    {
        return response()->json([
            'message' => 'Formulario de creación de usuario RH',
            'data' => [],
        ], 200);
    }

    /**
     * Registrar nuevo usuario RH (role_clave = 3)
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:users,correo',
                    'password' => 'required|string|min:6',
                    'usuario' => 'nullable|string|max:255',
                    'departamento_id' => 'nullable|exists:departamentos,id',
                    'photo' => 'nullable|image|mimes:jpeg,jpg,png,gif'
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Foto por defecto
            $photoPath = 'photos/users.jpg';

            // Guardar nueva foto
            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }

                $file = $request->file('photo');
                $filename = 'photo_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);

                $photoPath = 'photos/' . $filename;
            }

            // Crear usuario
            $usuario = Users::create([
                'nombre' => $request->name,
                'correo' => $request->email,
                'usuario' => $request->input('usuario', 'RH'),
                'password' => Hash::make($request->password),
                'departamento_id' => $request->departamento_id,
                'photo' => $photoPath,
                'status_id' => 1, // Activo
            ]);

            // Asignar rol RH
            ModelHasRole::create([
                'role_clave' => 3,
                'model_clave' => $usuario->id,
                'model_type' => Users::class,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Usuario RH creado exitosamente',
                'user' => new UsuarioResource($usuario),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en store RH: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al crear usuario RH',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Datos de un usuario RH para edición
     */
    public function edit($id)
    {
        try {
            $usuario = Users::findOrFail($id);

            return response()->json([
                'message' => 'Datos obtenidos',
                'user' => new UsuarioResource($usuario),
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
     * Actualizar usuario RH
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            $usuario = Users::findOrFail($id);

            $validator = Validator::make(
                $request->all(),
                [
                    'name' => 'required|string|max:255',
                    'email' => 'required|email|unique:users,correo,' . $id . ',id',
                    'usuario' => 'nullable|string|max:255',
                    'departamento_id' => 'nullable|exists:departamentos,id',
                    'password' => 'nullable|string|min:6',
                    'current_password' => 'required_with:password|string',
                    'photo' => 'nullable|image|mimes:jpeg,jpg,png,gif'
                ],
                ValidationMessages::messages()
            );

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Datos inválidos',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Foto actual
            $photoPath = $usuario->photo;
            $defaultPhoto = 'photos/users.jpg';

            // Subida de nueva foto
            if ($request->hasFile('photo')) {
                if (!file_exists(public_path('photos'))) {
                    mkdir(public_path('photos'), 0777, true);
                }

                $file = $request->file('photo');
                $filename = 'photo_' . $id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('photos'), $filename);
                $photoPath = 'photos/' . $filename;

                // Eliminar foto anterior
                if ($usuario->photo !== $defaultPhoto) {
                    $oldPath = public_path($usuario->photo);
                    if (file_exists($oldPath)) {
                        @unlink($oldPath);
                    }
                }
            }

            // Verificar contraseña para cambio
            if ($request->filled('password')) {
                if (!Hash::check($request->current_password, auth()->user()->password)) {
                    return response()->json([
                        'message' => 'Contraseña actual incorrecta'
                    ], 403);
                }

                $usuario->password = Hash::make($request->password);
            }

            // Actualizar datos
            $usuario->update([
                'nombre' => $request->name,
                'correo' => $request->email,
                'usuario' => $request->usuario ?? $usuario->usuario,
                'departamento_id' => $request->departamento_id,
                'photo' => $photoPath,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Usuario RH actualizado correctamente',
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Error en update RH: ' . $e->getMessage());

            return response()->json([
                'message' => 'Error al actualizar usuario RH',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Eliminar usuario RH
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            if ((int)auth()->id() === (int)$id) {
                return response()->json([
                    'message' => 'No puedes eliminar tu propio usuario.'
                ], 403);
            }

            $usuario = Users::findOrFail($id);

            // Eliminar roles
            ModelHasRole::where('model_clave', $id)
                ->where('model_type', Users::class)
                ->delete();

            // Eliminar foto si no es la default
            $defaultPhoto = 'photos/users.jpg';
            if ($usuario->photo !== $defaultPhoto) {
                $photoPath = public_path($usuario->photo);
                if (file_exists($photoPath)) {
                    @unlink($photoPath);
                }
            }

            $usuario->delete();

            DB::commit();

            return response()->json([
                'message' => 'Usuario RH eliminado correctamente',
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (ModelNotFoundException $e) {
            DB::rollBack();
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
