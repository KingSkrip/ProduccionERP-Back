<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\UsuarioResource;

class DataDashboardController extends Controller
{
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';

    public function __construct()
    {
        // Obtener la clave secreta del .env
        $this->jwtSecret = env('JWT_SECRET');
    }

    public function me(Request $request)
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json(['message' => 'Token no proporcionado'], 401);
            }

            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            $usuario = Usuario::where('CLAVE', $decoded->sub)->first();

            if (!$usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }

            return response()->json([
                'user' => new UsuarioResource($usuario)
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token inválido'], 401);
        }
    }


    public function updateStatus(Request $request)
    {
        $request->validate([
            'status' => 'required|string'
        ]);

        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Token requerido'], 401);
        }

        $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

        $usuario = Usuario::find($decoded->sub);
        $usuario->STATUS = $request->status;
        $usuario->save();

        return response()->json([
            'message' => 'Status actualizado',
            'user' => new UsuarioResource($usuario)
        ]);
    }
}
