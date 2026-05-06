<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use App\Services\FirebirdEmpresaService;
use App\Services\FirebirdConnectionService;

class FirebirdEmpresaManualService
{

protected FirebirdEmpresaService $empresaService;
protected FirebirdConnectionService $connectionService;
protected $fb;

public function __construct(
    FirebirdEmpresaService $empresaService,
    FirebirdConnectionService $connectionService
) {
    $this->empresaService = $empresaService;
    $this->connectionService = $connectionService;
}

protected function fb()
{
    if (!$this->fb) {
        $empresa = $this->empresaService->getEmpresa();

        // 🔥 si ya hiciste multi-empresa:
        $this->fb = $this->connectionService->getConnectionByEmpresa($empresa);

        // 🔹 si NO:
        // $this->fb = $this->connectionService->getProductionConnection();
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
public function getOperationalTable(string $prefix, ?string $date = null, int $maxWeeksBack = 8)
{
    $this->validateTableName($prefix);

    $empresa = $this->empresaService->getEmpresa();
    $date = $date ? Carbon::parse($date) : Carbon::today();
    $fb = $this->fb();

    for ($i = 0; $i <= $maxWeeksBack; $i++) {

        $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
        $lastSunday = $date->copy()->subDays($daysSinceSunday);

        $formattedDate = $lastSunday->format('dmy');
        $table = strtoupper($prefix) . $formattedDate . $empresa;

        try {
            $fb->select("SELECT FIRST 1 * FROM {$table}");

            return collect(
                $fb->select("SELECT * FROM {$table}")
            );
        } catch (\Exception $e) {
            $date->subDays(7);
        }
    }

    return collect();
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

    public function getTbByTablaYClave(string $tabla, string $claveTrab)
    {
        $this->validateTableName($tabla);

        return collect(
            $this->fb()->select(
                "SELECT * FROM {$tabla} WHERE CLAVE_TRAB = ?",
                [$claveTrab]
            )
        )->first();
    }

   public function getTbByClave(string $claveTrab, ?string $date = null)
{
    $date = $date ? Carbon::parse($date) : Carbon::today();
    $empresa = $this->empresaService->getEmpresa();
    $fb = $this->fb();
    for ($i = 0; $i <= 8; $i++) {
        $daysSinceSunday = ($date->dayOfWeek - Carbon::SUNDAY + 7) % 7;
        $lastSunday = $date->copy()->subDays($daysSinceSunday);
        $formattedDate = $lastSunday->format('dmy');
        $table = "TB{$formattedDate}{$empresa}";
        try {
            return collect(
                $fb->select(
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