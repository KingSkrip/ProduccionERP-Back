<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 🔥 Registrar el guard JWT
        Auth::extend('jwt_driver', function ($app, $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider']),
                $app['request']
            );
        });
    }
}