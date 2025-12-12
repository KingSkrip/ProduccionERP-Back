<?php

namespace App\Http\Controllers\Personalizacion\Perfil;

use App\Helpers\ValidationMessages;
use App\Http\Controllers\Controller;
use App\Models\Direccion;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PerfilController extends Controller
{
    /**
     * Actualizar datos del usuario (nombre, correo, usuario, foto, dirección, etc)
     */
    public function updatePerfil(Request $request)
    {
        $user = auth()->user();

        // Mapear automáticamente inglés → español
        $mapped = [
            // usuario
            'name'     => 'nombre',
            'username' => 'usuario',
            'email'    => 'correo',
            'phone'    => 'telefono',

            // dirección
            // 'calle'   => 'calle',
            // 'no_ext'   => 'no_ext',
            // 'no_int'   => 'no_int',
            // 'colonia' => 'colonia',
            // 'cp'      => 'cp',
            // 'municipio'     => 'municipio',
            // 'estado'    => 'estado',
            // 'entidad_federativa' => 'entidad_federativa'
        ];

        // Convertir los nombres que vengan en inglés
        foreach ($mapped as $en => $es) {
            if ($request->has($en)) {
                $request->merge([$es => $request->$en]);
            }
        }

        // Validación
        $request->validate([
            'nombre'    => 'nullable|string|max:255',
            'correo'    => ['nullable', 'email', 'max:255', Rule::unique('users', 'correo')->ignore($user->id)],
            'telefono'  => ['nullable', 'string', 'max:20', Rule::unique('users', 'telefono')->ignore($user->id)],
            'usuario'   => 'nullable|string|max:255',
            'photo'     => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048',

            // dirección
            'calle'              => 'nullable|string|max:255',
            'no_ext'             => 'nullable|string|max:50',
            'no_int'             => 'nullable|string|max:50',
            'colonia'            => 'nullable|string|max:255',
            'cp'                 => 'nullable|string|max:10',
            'municipio'          => 'nullable|string|max:255',
            'estado'             => 'nullable|string|max:255',
            'entidad_federativa' => 'nullable|string|max:255',
        ]);

        // Actualizar usuario
        $user->update([
            'nombre'   => $request->nombre,
            'correo'   => $request->correo,
            'usuario'  => $request->usuario,
            'telefono' => $request->telefono,
        ]);

        // Foto
        // Foto
        try {
            if ($request->hasFile('photo')) {
                $file = $request->file('photo');
                $filename = 'photo_' . $user->id . '_' . time() . '.' . $file->getClientOriginalExtension();

                $file->move(public_path('photos'), $filename);

                $user->photo = 'photos/' . $filename;
                $user->save();
            }
        } catch (\Exception $e) {
            Log::error("Error al subir foto: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al subir la foto'
            ], 500);
        }


        // Dirección
        $direccionData = $request->only([
            'calle',
            'no_ext',
            'no_int',
            'colonia',
            'cp',
            'municipio',
            'estado',
            'entidad_federativa'
        ]);

        if ($user->direccion) {
            // Actualiza la dirección existente
            $user->direccion->update($direccionData);
        } else if (!empty(array_filter($direccionData))) {
            // Solo crea si hay algún dato
            $direccion = Direccion::create($direccionData);
            $user->direccion_id = $direccion->id;
            $user->save();
        }


        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado correctamente',
            'user'    => $user->load('direccion', 'departamento')
        ], 200);
    }


    /**
     * Cambiar contraseña
     */
    public function updatePassword(Request $request)
    {
        try {
            $user = auth()->user();

            $request->validate([
                'password_nueva' => 'required|min:8',
            ]);

            $user->password = Hash::make($request->password_nueva);
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada correctamente'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar contraseña: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al actualizar la contraseña'
            ], 500);
        }
    }

    /**
     * Eliminar cuenta
     */
    public function destroy(Request $request)
    {
        try {
            $user = auth()->user();

            if ($request->has('password') && !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Contraseña incorrecta'
                ], 422);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'Cuenta eliminada correctamente'
            ], 200);
        } catch (Exception $e) {
            Log::error('Error al eliminar cuenta: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al eliminar la cuenta'
            ], 500);
        }
    }

    /**
     * Mostrar información del usuario
     */
    public function show()
    {
        try {
            $user = auth()->user()->load('direccion', 'departamento');

            return response()->json([
                'success' => true,
                'data' => $user
            ], 200);
        } catch (Exception $e) {
            Log::error('Error al obtener datos del usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al obtener la información del usuario'
            ], 500);
        }
    }
}
