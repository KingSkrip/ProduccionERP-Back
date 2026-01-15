<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FirebirdEmpresaService
{
    protected string $empresa;

    public function __construct()
    {
        $fbDatabase = env('FB_DATABASE'); // srvasp01old
        preg_match('/\d{2}/', $fbDatabase, $matches);
        $this->empresa = $matches[0] ?? '00';
    }

    /* ==========================================================
     | 🔌 Conexión dinámica
     ========================================================== */
   public function getConnection()
{
    $databaseName = "SRVNOI{$this->empresa}";

    Log::info('🔥 Firebird dinámica', [
        'empresa' => $this->empresa,
        'database' => $databaseName,
        'host' => env('FB_HOST'),
        'port' => env('FB_PORT'),
        'user' => env('FB_USERNAME'),
    ]);

    config([
        'database.connections.firebird_dinamica' => [
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

    DB::purge('firebird_dinamica');

    $connection = DB::connection('firebird_dinamica');

    Log::info('✅ Conectado a Firebird', [
        'database' => $connection->getConfig('database'),
    ]);

    return $connection;
}

    /* ==========================================================
     | 1️⃣ TABLAS MAESTRAS (DEPTOS01, PUESTOS02, AREAS04)
     ========================================================== */
    public function getMasterTable(string $baseTable)
    {
        $this->validateTableName($baseTable);

        $table = strtoupper($baseTable) . $this->empresa;

        return collect(
            $this->getConnection()->select("SELECT * FROM {$table}")
        );
    }

    /* ==========================================================
     | 2️⃣ TABLAS OPERATIVAS POR FECHA (FT, TB, etc.)
     ========================================================== */
    // public function getOperationalTable(
    //     string $prefix,
    //     ?string $date = null
    // ) {
    //     $this->validateTableName($prefix);

    //     $date = $date ? Carbon::parse($date) : Carbon::today();

    //     // 🔥 Último domingo (tu regla de negocio)
    //     $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
    //     $lastSunday = $date->copy()->subDays($daysSinceSunday);

    //     $formattedDate = $lastSunday->format('dmy');

    //     $table = strtoupper($prefix) . $formattedDate . $this->empresa;

    //     return collect(
    //         $this->getConnection()->select("SELECT * FROM {$table}")
    //     );
    // }



    public function getOperationalTable(
        string $prefix,
        ?string $date = null,
        int $maxWeeksBack = 8
    ) {
        $this->validateTableName($prefix);

        $date = $date ? Carbon::parse($date) : Carbon::today();

        Log::info('📆 Fecha base inicial', [
            'fecha' => $date->toDateString(),
            'empresa' => $this->empresa,
            'prefix' => $prefix
        ]);

        for ($i = 0; $i <= $maxWeeksBack; $i++) {

            // 🔥 Último domingo
            $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
            $lastSunday = $date->copy()->subDays($daysSinceSunday);

            $formattedDate = $lastSunday->format('dmy');
            $table = strtoupper($prefix) . $formattedDate . $this->empresa;

            Log::info("🔍 Intento {$i}", [
                'fecha_usada' => $lastSunday->toDateString(),
                'tabla' => $table
            ]);

            try {
                // Test rápido
                $this->getConnection()
                    ->select("SELECT FIRST 1 * FROM {$table}");

                Log::info('✅ Tabla encontrada', [
                    'intento' => $i,
                    'fecha_encontrada' => $lastSunday->toDateString(),
                    'tabla' => $table
                ]);

                return collect(
                    $this->getConnection()->select("SELECT * FROM {$table}")
                );
            } catch (\Exception $e) {
                Log::warning('❌ Tabla no existe, retrocediendo 7 días', [
                    'tabla' => $table,
                    'fecha_fallida' => $lastSunday->toDateString(),
                    'error' => $e->getMessage()
                ]);

                // ⏪ retroceder 1 semana
                $date->subDays(7);
            }
        }

        Log::error('🛑 No se encontró ninguna tabla válida', [
            'prefix' => $prefix,
            'empresa' => $this->empresa,
            'maxWeeksBack' => $maxWeeksBack
        ]);

        return collect();
    }



    /* ==========================================================
     | 3️⃣ TABLAS FIJAS (USUARIOS, ROLES, ETC.)
     ========================================================== */
    public function getFixedTable(string $table)
    {
        $this->validateTableName($table);

        return collect(
            $this->getConnection()->select(
                "SELECT * FROM " . strtoupper($table)
            )
        );
    }

    /* ==========================================================
     | 🔐 Seguridad básica
     ========================================================== */
    protected function validateTableName(string $name)
    {
        if (!preg_match('/^[A-Z0-9_]+$/', strtoupper($name))) {
            throw new InvalidArgumentException('Nombre de tabla inválido');
        }
    }
}
