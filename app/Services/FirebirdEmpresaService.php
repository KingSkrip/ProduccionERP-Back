<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class FirebirdEmpresaService
{
    protected string $empresa;

    public function __construct()
    {
        $fbDatabase = env('FB_DATABASE'); // srvasp01old
        preg_match('/\d{2}/', $fbDatabase, $matches);
        $this->empresa = $matches[0] ?? '00';
    }


    public function setEmpresa(string $empresa): void
    {
        $this->empresa = $empresa;
    }

    public function getEmpresa(): string
    {
        return $this->empresa;
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
}
