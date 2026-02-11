<?php

namespace App\Http\Middleware;

use App\Models\Firebird\Users;
use Closure;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class JwtAuth
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $token = $request->bearerToken();

            if (!$token) {
                Log::warning('❌ JWT: No token provided');
                return response()->json([
                    'message' => 'Token no proporcionado'
                ], 401);
            }

            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));

            Log::info('🔓 JWT decoded', [
                'sub' => $decoded->sub,
                'exp' => $decoded->exp ?? null,
            ]);

            $usuario = Users::find($decoded->sub);

            if (!$usuario) {
                Log::warning('❌ JWT: User not found', ['sub' => $decoded->sub]);
                return response()->json([
                    'message' => 'Usuario no encontrado'
                ], 401);
            }

            Log::info('✅ JWT: User authenticated', [
                'firebird_id' => $usuario->ID,
                'firebird_clave' => $usuario->CLAVE,
                'email' => $usuario->CORREO,
            ]);

            // 🔥 IMPORTANTE: Setear el usuario en Auth para que Broadcasting lo use
            Auth::setUser($usuario);
            
            // También guardarlo en request por si otros middlewares lo necesitan
            $request->merge(['usuario_auth' => $usuario]);

            return $next($request);

        } catch (Exception $e) {
            Log::error("❌ JWT inválido", [
                'error' => $e->getMessage(),
                'token' => substr($token ?? '', 0, 20) . '...',
            ]);
            
            return response()->json([
                'message' => 'Token inválido o expirado'
            ], 401);
        }
    }
}