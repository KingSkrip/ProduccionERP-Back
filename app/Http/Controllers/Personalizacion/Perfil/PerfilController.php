<?php

namespace App\Http\Controllers\Personalizacion\Perfil;

use App\Helpers\ValidationMessages;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;


class PerfilController extends Controller
{
    /**
     * Actualizar datos del usuario (nombre, correo, usuario, foto, etc)
     */


    public function updatePerfil(Request $request)
    {
        $user = auth()->user();

        // <-- Validación aquí directamente, sin try/catch
        $request->validate([
            'NOMBRE'       => 'required|string|max:255',
            'CORREO'       => [
                'required',
                'email',
                'max:255',
                Rule::unique('USUARIOS', 'CORREO')->ignore($user->CLAVE, 'CLAVE')
            ],
            'USUARIO'      => 'required|string|max:255',
            'DEPARTAMENTO' => 'nullable|string|max:255',
            'FOTO'         => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048',
        ], ValidationMessages::messages());

        $data = [
            'NOMBRE'       => $request->NOMBRE,
            'CORREO'       => $request->CORREO,
            'USUARIO'      => $request->USUARIO,
            'DEPARTAMENTO' => $request->DEPARTAMENTO,
        ];

        if ($request->hasFile('FOTO')) {
            $file = $request->file('FOTO');
            if ($user->PHOTO && file_exists(public_path($user->PHOTO))) {
                unlink(public_path($user->PHOTO));
            }
            $filename = 'photo_' . $user->CLAVE . '_' . time() . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('photos'), $filename);
            $data['PHOTO'] = 'photos/' . $filename;
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Perfil actualizado correctamente',
            'user' => $user
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

            $user->PASSWORD2 = Hash::make($request->password_nueva);
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

            if ($request->has('password') && !Hash::check($request->password, $user->PASSWORD)) {
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
        } catch (\Exception $e) {
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
    public function show(Request $request)
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $request->user()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener datos del usuario: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al obtener la información del usuario'
            ], 500);
        }
    }
}
