<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Connection;

class FirebirdConnectionService
{
    protected array $connections = [];

    public function getProductionConnection(): Connection
    {
        config([
            'database.connections.firebird_produccion' => [
                'driver'            => 'firebird',
                'host'              => env('FB_HOST'),
                'port'              => env('FB_PORT'),
                'database'          => env('FB_DATABASE'),
                'username'          => env('FB_USERNAME'),
                'password'          => env('FB_PASSWORD'),
                'charset'           => env('FB_CHARSET', 'UTF8'),
                'dialect'           => 3,
                'quote_identifiers' => false,
            ]
        ]);

        DB::purge('firebird_produccion');
        return DB::connection('firebird_produccion');
    }


    public function getConnectionByEmpresa(string $empresa): Connection
    {
        if (isset($this->connections[$empresa])) {
            return $this->connections[$empresa];
        }

        $databaseName = "SRVNOI{$empresa}";
        $connectionName = "firebird_{$empresa}";

        config([
            "database.connections.{$connectionName}" => [
                'driver'   => 'firebird',
                'host'     => env('FB_HOST'),
                'port'     => env('FB_PORT'),
                'database' => $databaseName,
                'username' => env('FB_USERNAME'),
                'password' => env('FB_PASSWORD'),
                'charset'  => env('FB_CHARSET', 'UTF8'),
                'dialect'  => 3,
                'quote_identifiers' => false,
            ]
        ]);

        DB::purge($connectionName);

        return $this->connections[$empresa] = DB::connection($connectionName);
    }
}