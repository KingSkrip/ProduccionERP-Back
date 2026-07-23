<?php

namespace App\Console\Commands;

use App\Models\UserFirebirdIdentity;
use Illuminate\Console\Command;

class AsignarCredencialesFirebird extends Command
{
    protected $signature = 'firebird:asignar-credenciales
                            {--dry-run : Solo muestra que se generaría, sin guardar}';

    protected $description = 'Genera numero_credencial (10 dígitos) a partir de firebird_empresa + firebird_user_clave para los usuarios que tengan empresa';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $query = UserFirebirdIdentity::query()
            ->whereNotNull('firebird_empresa')
            ->where('firebird_empresa', '!=', '');

        $total = $query->count();
        $this->info("Registros con empresa encontrados: {$total}");

        $actualizados = 0;
        $omitidos = 0;

        $query->chunkById(200, function ($registros) use (&$actualizados, &$omitidos, $dryRun) {
            foreach ($registros as $registro) {
                $empresa = str_pad((string) $registro->firebird_empresa, 2, '0', STR_PAD_LEFT);
                $claveOriginal = (string) $registro->firebird_user_clave;

                if (strlen($claveOriginal) > 8) {
                    $this->warn("ID {$registro->id}: firebird_user_clave demasiado largo ({$claveOriginal}), se omite.");
                    $omitidos++;
                    continue;
                }

                $clave = str_pad($claveOriginal, 8, '0', STR_PAD_LEFT);
                $credencial = $empresa . $clave;

                if ($dryRun) {
                    $this->line("ID {$registro->id} -> {$credencial}");
                } else {
                    $registro->numero_credencial = $credencial;
                    $registro->timestamps = false;
                    $registro->save();
                }

                $actualizados++;
            }
        });

        $this->info("Procesados: {$actualizados} | Omitidos: {$omitidos}");
        $this->info($dryRun ? 'Modo dry-run: no se guardó nada.' : 'Credenciales asignadas correctamente.');

        return Command::SUCCESS;
    }
}