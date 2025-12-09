<?php

namespace App\Http\Controllers\Personalizacion\Dashboard;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\UsuarioResource;
use App\Models\Users;

class DataDashboardController extends Controller
{
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';

    public function __construct()
    {
        $this->jwtSecret = env('JWT_SECRET');
    }

    /**
     * Obtener datos del usuario actual
     * 🔥 CORRECCIÓN: Buscar por 'id' en lugar de 'CLAVE'
     */
    public function me(Request $request)
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json(['message' => 'Token no proporcionado'], 401);
            }

            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            // 🔥 CORRECCIÓN: Usar find() porque $decoded->sub contiene el ID
            $usuario = Users::find($decoded->sub);

            if (!$usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }

            return response()->json([
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en me(): ' . $e->getMessage());
            return response()->json(['message' => 'Token inválido'], 401);
        }
    }

    /**
     * Actualizar status del usuario
     */
    public function updateStatus(Request $request)
    {
        try {
            $request->validate([
                'status' => 'required|string'
            ]);

            $token = $request->bearerToken();

            if (!$token) {
                return response()->json(['message' => 'Token requerido'], 401);
            }

            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            $usuario = Users::find($decoded->sub);

            if (!$usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }

            $usuario->status_id = $request->status;
            $usuario->save();

            return response()->json([
                'message' => 'Status actualizado',
                'user' => new UsuarioResource($usuario)
            ]);
        } catch (\Exception $e) {
            Log::error('Error en updateStatus(): ' . $e->getMessage());
            return response()->json(['message' => 'Error al actualizar status'], 500);
        }
    }
}
