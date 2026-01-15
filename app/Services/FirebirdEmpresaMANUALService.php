<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FirebirdEmpresaManualService
{
    protected string $empresa = '04';
    protected string $empresaManual = '04';
    protected string $databasePrefix = 'SRVNOI'; // 🔥 NUEVO: Prefijo configurable

    public function __construct(?string $empresaManual = '04', ?string $databasePrefix = null)
    {
        /*
    |--------------------------------------------------------------------------
    | 🏢 EMPRESA MANUAL (TEMPORAL)
    |--------------------------------------------------------------------------
    | 👉 AQUÍ PUEDES FIJAR LA EMPRESA MANUALMENTE
    | Ejemplo: '01', '02', etc.
    |
    | Cuando ya no se ocupe manual:
    | - elimina este bloque
    | - vuelve a activar la detección por FB_DATABASE
    */

        // 🔥 Si se pasa un prefijo personalizado, usarlo
        if ($databasePrefix !== null) {
            $this->databasePrefix = $databasePrefix;
        }

        if ($empresaManual !== null) {
            $this->empresa = str_pad($empresaManual, 2, '0', STR_PAD_LEFT);
            $this->empresaManual = $empresaManual;

            // Log::info('🏢 Empresa configurada manualmente', [
            //     'empresa' => $this->empresa,
            //     'empresaManual' => $empresaManual,
            //     'databasePrefix' => $this->databasePrefix,
            // ]);

            return;
        }

        // 🔁 FALLBACK AUTOMÁTICO (POR SI NO PASAS NADA)
        $fbDatabase = env('FB_DATABASE'); // Ej: srvasp01old, SRVNOI04
        preg_match('/\d{2}/', $fbDatabase, $matches);
        $this->empresa = $matches[0] ?? '00';

        // Detectar el prefijo de la base
        if (stripos($fbDatabase, 'srvasp') !== false) {
            $this->databasePrefix = 'SRVASP';
        } elseif (stripos($fbDatabase, 'srvnoi') !== false) {
            $this->databasePrefix = 'SRVNOI';
        }

        // Log::info('🔁 Empresa detectada automáticamente', [
        //     'empresa' => $this->empresa,
        //     'fbDatabase' => $fbDatabase,
        //     'databasePrefix' => $this->databasePrefix,
        // ]);
    }


    /* ==========================================================
     | 🔌 Conexión dinámica
     ========================================================== */
    public function getConnection()
    {
        // 🔥 AHORA USA EL PREFIJO CORRECTO
        $databaseName = "{$this->databasePrefix}{$this->empresa}";

        // Log::info('🔥 Firebird dinámica', [
        //     'empresa' => $this->empresa,
        //     'database' => $databaseName,
        //     'prefix' => $this->databasePrefix,
        //     'host' => env('FB_HOST'),
        //     'port' => env('FB_PORT'),
        //     'user' => env('FB_USERNAME'),
        // ]);

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

        // Log::info('✅ Conectado a Firebird', [
        //     'database' => $connection->getConfig('database'),
        // ]);

        return $connection;
    }

    /* ==========================================================
     | 1️⃣ TABLAS MAESTRAS (DEPTOS01, PUESTOS02, AREAS04)
     ========================================================== */
    public function getMasterTable(string $baseTable)
    {
        $this->validateTableName($baseTable);

        $table = strtoupper($baseTable) . $this->empresa;

        // Log::info('🔍 Obteniendo MasterTable', [
        //     'baseTable' => $baseTable,
        //     'tabla_completa' => $table,
        //     'empresa' => $this->empresa,
        //     'database' => "{$this->databasePrefix}{$this->empresa}"
        // ]);

        try {
            $result = collect(
                $this->getConnection()->select("SELECT * FROM {$table}")
            );

            // Log::info('✅ MasterTable encontrada', [
            //     'tabla' => $table,
            //     'total_registros' => $result->count(),
            //     'primeros_registros' => $result->take(3)->toArray()
            // ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('❌ Error al obtener MasterTable', [
                'tabla' => $table,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /* ==========================================================
     | 2️⃣ TABLAS OPERATIVAS POR FECHA (FT, TB, etc.)
     ========================================================== */
    public function getOperationalTable(
        string $prefix,
        ?string $date = null,
        int $maxWeeksBack = 8
    ) {
        $this->validateTableName($prefix);

        $date = $date ? Carbon::parse($date) : Carbon::today();

        // Log::info('📆 Fecha base inicial', [
        //     'fecha' => $date->toDateString(),
        //     'empresa' => $this->empresa,
        //     'prefix' => $prefix,
        //     'database' => "{$this->databasePrefix}{$this->empresa}"
        // ]);

        for ($i = 0; $i <= $maxWeeksBack; $i++) {

            // 🔥 Último domingo
            $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
            $lastSunday = $date->copy()->subDays($daysSinceSunday);

            $formattedDate = $lastSunday->format('dmy');
            $table = strtoupper($prefix) . $formattedDate . $this->empresa;

            // Log::info("🔍 Intento {$i}", [
            //     'fecha_usada' => $lastSunday->toDateString(),
            //     'tabla' => $table
            // ]);

            try {
                // Test rápido
                $this->getConnection()
                    ->select("SELECT FIRST 1 * FROM {$table}");

                // Log::info('✅ Tabla encontrada', [
                //     'intento' => $i,
                //     'fecha_encontrada' => $lastSunday->toDateString(),
                //     'tabla' => $table
                // ]);

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

    public function getTbByTablaYClave(string $tabla, string $claveTrab)
    {
        $this->validateTableName($tabla);

        return collect(
            $this->getConnection()->select(
                "SELECT * FROM {$tabla} WHERE CLAVE_TRAB = ?",
                [$claveTrab]
            )
        )->first();
    }

    public function getTbByClave(string $claveTrab, ?string $date = null)
    {
        $this->validateTableName('TB');

        $date = $date ? Carbon::parse($date) : Carbon::today();

        for ($i = 0; $i <= 8; $i++) {

            $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
            $lastSunday = $date->copy()->subDays($daysSinceSunday);
            $formattedDate = $lastSunday->format('dmy');

            $table = "TB{$formattedDate}{$this->empresa}";

            try {
                return collect(
                    $this->getConnection()->select(
                        "SELECT * FROM {$table} WHERE CLAVE_TRAB = ?",
                        [$claveTrab]
                    )
                )->first();
            } catch (\Throwable $e) {
                $date->subDays(7);
            }
        }

        return null;
    }
}
