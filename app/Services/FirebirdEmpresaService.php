<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class FirebirdEmpresaService
{
    protected string $empresa;

    public function __construct()
    {
        $this->empresa = $this->resolverEmpresa();
    }

    protected function resolverEmpresa(): string
    {
        $fbDatabase = env('FB_DATABASE', '');
        preg_match('/\d{2}/', $fbDatabase, $matches);
        $empresa = $matches[0] ?? '03';
        Log::info('empresa de pedidos', [
            'empresa'     => $empresa,
            'fb_database' => $fbDatabase,
        ]);
        return $empresa;
    }

    public function getEmpresa(): string
    {
        return $this->empresa;
    }
}