<?php

namespace App\Http\Middleware;

use App\Models\Users;
use Closure;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class JwtAuth
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                return response()->json([
                    'message' => 'Token no proporcionado'
                ], 401);
            }

            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));

            $usuario = Users::find($decoded->sub);

            if (!$usuario) {
                return response()->json([
                    'message' => 'Usuario no encontrado'
                ], 401);
            }

            // Adjuntar usuario al request
            $request->merge(['usuario_auth' => $usuario]);

            return $next($request);

        } catch (Exception $e) {
            Log::error("JWT inválido: ".$e->getMessage());
            return response()->json([
                'message' => 'Token inválido o expirado'
            ], 401);
        }
    }
}
