<?php
// app/Http/Controllers/Checador/ChecadorBroadcastAuthController.php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class ChecadorBroadcastAuthController extends Controller
{
    private const ROL_GUARDIA = 'GUARDIA';

    public function auth(Request $request)
    {
        $identity = $this->identityDesdeToken($request);

        $esGuardia = $identity->roles()
            ->where('nombre', self::ROL_GUARDIA)
            ->exists();

        if (! $esGuardia) {
            abort(403, 'No autorizado para este canal');
        }

        $request->setUserResolver(fn () => $identity);

        try {
            return Broadcast::auth($request);
        } catch (BroadcastException $e) {
            abort(403, $e->getMessage());
        }
    }

    private function identityDesdeToken(Request $request): UserFirebirdIdentity
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Token requerido');
        }

        $decoded = JWT::decode($token, new Key(config('jwt.secret'), 'HS256'));

        $usuario = Users::find((int) $decoded->sub);
        if (! $usuario) {
            abort(404, 'Usuario no encontrado');
        }

        $identity = UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->ID)->first()
            ?? UserFirebirdIdentity::where('firebird_user_clave', (int) $usuario->CLAVE)->first();

        if (! $identity) {
            abort(404, 'Identidad no encontrada');
        }

        return $identity;
    }
}