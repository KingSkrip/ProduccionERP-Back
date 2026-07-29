<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\UserFirebirdIdentity;
use App\Models\Turno;

class SyncUserTurnosPorEmpresa extends Command
{
    protected $signature = 'mysql:sync-user-turnos-empresa 
        {--empresa= : Procesar solo una empresa (01,02,03,04,05)}';

    protected $description = 'Asigna turnos a users_firebird_identities según su empresa';

    public function handle()
    {
        $empresaFiltro = $this->option('empresa');

        $this->info('🔥 Iniciando asignación de turnos');

        try {
            // ============================================
            // FILTRAR IDENTIDADES (OBLIGATORIO TENER EMPRESA)
            // ============================================
            $identidadesQuery = UserFirebirdIdentity::query()
                ->whereNotNull('firebird_empresa');

            if ($empresaFiltro) {
                $empresaFiltro = str_pad($empresaFiltro, 2, '0', STR_PAD_LEFT);
                $identidadesQuery->where('firebird_empresa', $empresaFiltro);
                $this->info("🏢 Procesando SOLO empresa {$empresaFiltro}");
            } else {
                $this->info('🏢 Procesando TODAS las empresas');
            }

            $identidades = $identidadesQuery->get();

            if ($identidades->isEmpty()) {
                $this->warn('⚠️ No se encontraron identidades válidas');
                return 0;
            }

            $asignados = 0;
            $omitidos = 0;
            $sinTurno = 0;

            foreach ($identidades as $identity) {

                // ============================================
                // NORMALIZAR EMPRESA
                // ============================================
                $empresaIdentity = str_pad(
                    trim($identity->firebird_empresa),
                    2,
                    '0',
                    STR_PAD_LEFT
                );

                // ============================================
                // VALIDAR TURNO ACTIVO
                // ============================================
                $yaTieneTurno = $identity->turnos()
                    ->where('status_id', 1)
                    ->exists();

                if ($yaTieneTurno) {
                    $omitidos++;
                    continue;
                }

                // ============================================
                // BUSCAR TURNO ACTIVO POR EMPRESA
                // ============================================
                $turno = Turno::activo()
                    ->empresa($empresaIdentity)
                    ->first();

                if (!$turno) {
                    $this->warn("⚠️ Sin turno activo para empresa {$empresaIdentity}");
                    $sinTurno++;
                    continue;
                }

                // ============================================
                // ⏰ CALCULAR FECHA_FIN AUTOMÁTICAMENTE
                // ============================================
                $fechaInicio = Carbon::now();
                $fechaFin = $this->calcularFechaFin($fechaInicio, $turno);

                // ============================================
                // CREAR USER_TURNO
                // ============================================
                DB::connection('mysql')
                    ->table('user_turnos')
                    ->insert([
                        'user_firebird_identity_id' => $identity->id,
                        'turno_id' => $turno->id,
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                        'semana_anio' => Carbon::now()->weekOfYear,
                        'dias_descanso_personalizados' => null,
                        'status_id' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                $this->info("✅ Identity {$identity->id} → Turno {$turno->nombre} | Inicio: {$fechaInicio->format('Y-m-d H:i')} → Fin: {$fechaFin->format('Y-m-d H:i')}");
                $asignados++;
            }

            // ============================================
            // RESUMEN
            // ============================================
            $this->newLine();
            $this->info('🎯 RESUMEN');
            $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            $this->info("✅ Turnos asignados: {$asignados}");
            $this->info("⏭️  Omitidos (ya tenían turno): {$omitidos}");
            $this->info("❌ Sin turno configurado: {$sinTurno}");

            return 0;

        } catch (\Throwable $e) {
            $this->error('💥 Error fatal');
            Log::error('Error asignando turnos por empresa', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return 1;
        }
    }

    /**
     * 🕐 Calcula fecha_fin según el turno
     * Para 12x12: suma 12 horas
     * Para otros: usa hora_entrada y hora_salida
     */
    protected function calcularFechaFin(Carbon $fechaInicio, $turno): Carbon
    {
        // 🔥 CASO 1: Turno 12x12
        if (stripos($turno->nombre, '12x12') !== false || stripos($turno->nombre, '12 x 12') !== false) {
            return $fechaInicio->copy()->addHours(12);
        }

        // 🔥 CASO 2: Turno con hora_salida definida
        if (!empty($turno->hora_salida)) {
            $fechaFin = $fechaInicio->copy();
            
            // Parsear hora_salida (formato HH:MM:SS)
            $horaSalida = Carbon::createFromFormat('H:i:s', $turno->hora_salida);
            
            $fechaFin->setTime($horaSalida->hour, $horaSalida->minute, $horaSalida->second);

            // Si sale_dia_siguiente = 1, agregar 1 día
            if (!empty($turno->sale_dia_siguiente) && $turno->sale_dia_siguiente == 1) {
                $fechaFin->addDay();
            }

            return $fechaFin;
        }

        // 🔥 CASO 3: Turno con hora_fin (fallback)
        if (!empty($turno->hora_fin)) {
            $fechaFin = $fechaInicio->copy();
            $horaFin = Carbon::createFromFormat('H:i:s', $turno->hora_fin);
            $fechaFin->setTime($horaFin->hour, $horaFin->minute, $horaFin->second);
            return $fechaFin;
        }

        // 🔥 CASO 4: Default - suma 8 horas
        return $fechaInicio->copy()->addHours(8);
    }
}