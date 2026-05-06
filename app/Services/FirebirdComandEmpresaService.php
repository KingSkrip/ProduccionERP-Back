<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use App\Services\FirebirdEmpresaService;
use App\Services\FirebirdConnectionService;

class FirebirdComandEmpresaService
{

    protected FirebirdEmpresaService $empresaService;
    protected FirebirdConnectionService $connectionService;
    protected $fb;

    public function __construct(
        FirebirdEmpresaService $empresaService,
        FirebirdConnectionService $connectionService
    ) {
        /*
    |--------------------------------------------------------------------------
    | 🏢 EMPRESA MANUAL (TEMPORAL)
    |--------------------------------------------------------------------------
    | 👉 AQUÍ PUEDES FIJAR LA EMPRESA MANUALMENTE
    | Ejemplo: '01', '02', etc.
    |
    | Cuando ya no se ocupe manual:
    | - elimina este bloque
    | - vuelve a activar la detección por 
    */
        $this->empresaService = $empresaService;
        $this->connectionService = $connectionService;
    }


    protected function fb()
    {
        if (!$this->fb) {
            $this->fb = $this->connectionService->getProductionConnection();
        }
        return $this->fb;
    }

    /* ==========================================================
     | 1️⃣ TABLAS MAESTRAS (DEPTOS01, PUESTOS02, AREAS04)
     ========================================================== */
    public function getMasterTable(string $baseTable)
    {
        $this->validateTableName($baseTable);
        $empresa = $this->empresaService->getEmpresa();
        $table = strtoupper($baseTable) . $empresa;
        return collect(
            $this->fb()->select("SELECT * FROM {$table}")
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



    public function getOperationalTable(string $prefix, ?string $date = null, int $maxWeeksBack = 8): array
    {
        $this->validateTableName($prefix);
        $empresa = $this->empresaService->getEmpresa();
        $date = $date ? Carbon::parse($date) : Carbon::today();
        for ($i = 0; $i <= $maxWeeksBack; $i++) {
            $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
            $lastSunday = $date->copy()->subDays($daysSinceSunday);
            $formattedDate = $lastSunday->format('dmy');
            $table = strtoupper($prefix) . $formattedDate . $empresa;
            try {
                $this->fb()->select("SELECT FIRST 1 * FROM {$table}");
                return [
                    'data' => collect($this->fb()->select("SELECT * FROM {$table}")),
                    'table' => $table
                ];
            } catch (\Exception $e) {
                $date->subDays(7);
            }
        }
        return ['data' => collect(), 'table' => null];
    }




    /* ==========================================================
     | 3️⃣ TABLAS FIJAS (USUARIOS, ROLES, ETC.)
     ========================================================== */
    public function getFixedTable(string $table)
    {
        $this->validateTableName($table);
        return collect(
            $this->fb()->select(
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