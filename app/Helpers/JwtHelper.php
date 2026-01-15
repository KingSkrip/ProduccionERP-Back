<?php

namespace App\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\Firebird;

class JwtHelper
{
    public static function userFromToken(string $token): ?Users
    {
        $decoded = JWT::decode(
            $token,
            new Key(env('JWT_SECRET'), 'HS256')
        );

        return Users::find($decoded->sub);
    }
}
