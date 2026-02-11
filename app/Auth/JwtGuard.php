<?php

namespace App\Auth;

use App\Models\Firebird\Users;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JwtGuard implements Guard
{
    use GuardHelpers;

    protected $request;

    public function __construct($provider, Request $request)
    {
        $this->provider = $provider;
        $this->request = $request;
    }

    public function user()
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request->bearerToken();

        if (!$token) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key(config('jwt.secret'), 'HS256'));
            
            $this->user = Users::find($decoded->sub);
            
            Log::info('🔓 JwtGuard: User resolved', [
                'user_id' => $this->user?->ID ?? null,
            ]);
            
            return $this->user;
        } catch (\Exception $e) {
            Log::error('❌ JwtGuard: Invalid token', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function validate(array $credentials = [])
    {
        return false;
    }
}