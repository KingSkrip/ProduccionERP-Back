<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PerfilController extends Controller
{


    /**
     * Actualizar datos del usuario (nombre, correo, usuario, foto, etc)
     */
    public function updatePerfil(Request $request)
    {
        $user = auth()->user();

        $request->validate([
            'NOMBRE'        => 'required|string|max:255',
            'CORREO'        => 'required|email|max:255',
            'USUARIO'       => 'required|string|max:255',
            'DEPARTAMENTO'  => 'nullable|string|max:255',
            'FOTO'          => 'nullable|image|mimes:jpeg,jpg,png,gif|max:2048', // máx 2MB
        ]);

        // Preparar datos para actualizar
        $data = [
            'NOMBRE'       => $request->NOMBRE,
            'CORREO'       => $request->CORREO,
            'USUARIO'      => $request->USUARIO,
            'DEPARTAMENTO' => $request->DEPARTAMENTO,
        ];

        // Manejar la foto si se subió
        if ($request->hasFile('FOTO')) {
            $file = $request->file('FOTO');

            // Eliminar foto anterior si existe
            if ($user->PHOTO && file_exists(public_path($user->PHOTO))) {
                unlink(public_path($user->PHOTO));
            }

            // Generar nombre único para la foto
            $filename = 'photo_' . $user->CLAVE . '_' . time() . '.' . $file->getClientOriginalExtension();

            // Guardar en public/photos
            $file->move(public_path('photos'), $filename);

            // Guardar ruta relativa en BD
            $data['PHOTO'] = 'photos/' . $filename;
        }

        // Actualizar usuario
        $user->update($data);

        return response()->json([
            'message' => 'Perfil actualizado correctamente',
            'user' => $user
        ]);
    }

   /**
 * Cambiar contraseña
 */
public function updatePassword(Request $request)
{
    $user = auth()->user();

    $request->validate([
        'password_nueva' => 'required|min:8',
    ]);

    // actualizar contraseña directamente
    $user->PASSWORD2 = Hash::make($request->password_nueva);
    $user->save();

    return response()->json([
        'message' => 'Contraseña actualizada correctamente'
    ]);
}


    /**
     * Eliminar cuenta
     */
    public function destroy(Request $request)
    {
        $user = auth()->user();

        // si quieres validar contraseña para eliminar:
        if ($request->has('password') && !Hash::check($request->password, $user->PASSWORD)) {
            return response()->json([
                'error' => 'Contraseña incorrecta'
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'Cuenta eliminada correctamente'
        ]);
    }


    public function show(Request $request)
    {
        return response()->json([
            'data' => $request->user()
        ]);
    }
}
