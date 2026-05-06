<?php

namespace App\Console\Commands;

use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Services\CitaNotificacionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnviarRecordatoriosCitas extends Command
{
    protected $signature   = 'citas:recordatorios';
    protected $description = 'Envía recordatorios de WhatsApp 30 min, 1 hora antes, y avisos de citas pendientes';

    private CitaNotificacionService $notif;

    public function __construct(CitaNotificacionService $notif)
    {
        parent::__construct();
        $this->notif = $notif;
    }

    public function handle(): void
    {
        $tz    = env('APP_TIMEZONE', 'America/Mexico_City');
        $ahora = Carbon::now($tz);

        $this->info("⏰ Ejecutando recordatorios — {$ahora->format('Y-m-d H:i:s')} ({$tz})");

        // ── Recordatorios de citas CONFIRMADAS próximas ──
        $citas30 = $this->getCitasConfirmadasEnRango($ahora->copy()->addMinutes(30), 2, '30min');
        $citas60 = $this->getCitasConfirmadasEnRango($ahora->copy()->addMinutes(60), 2, '60min');

        $this->procesarRecordatorios($citas30, '30min');
        $this->procesarRecordatorios($citas60, '60min');

        // ── Avisos de citas PENDIENTES — solo entre 8am y 9am ──
        $hora = (int) $ahora->format('H');
        if ($hora >= 8 && $hora < 9) {
            $this->procesarPendientesDiaAnterior($ahora, $tz);
            $this->procesarPendientesMismoDia($ahora, $tz);
        }

        $this->info("✅ 30min: {$citas30->count()} | 60min: {$citas60->count()}");
    }

    // ─────────────────────────────────────────────
    // Solo CONFIRMADAS para recordatorios de 30/60 min
    // ─────────────────────────────────────────────
    private function getCitasConfirmadasEnRango(Carbon $objetivo, int $ventana, string $tipo)
    {
        $tz      = env('APP_TIMEZONE', 'America/Mexico_City');
        $desde   = $objetivo->copy()->subMinutes($ventana)->format('H:i:s');
        $hasta   = $objetivo->copy()->addMinutes($ventana)->format('H:i:s');
        $hoy     = Carbon::now($tz)->toDateString();
        $columna = $tipo === '30min' ? 'recordatorio_30min' : 'recordatorio_60min';

        $this->line("  🔍 [{$tipo}] {$hoy} entre {$desde} y {$hasta}");

        return Cita::whereDate('fecha', $hoy)
            ->whereTime('hora_inicio', '>=', $desde)
            ->whereTime('hora_inicio', '<=', $hasta)
            ->where('estado', 'confirmada')   // ← solo confirmadas
            ->whereNotNull('id_user')
            ->where($columna, false)
            ->get();
    }

    // ─────────────────────────────────────────────
    // PENDIENTES: notificar el DÍA ANTERIOR
    // Aplica a TODOS (anfitrión invitó proveedor Y proveedor agendó visita)
    // ─────────────────────────────────────────────
    private function procesarPendientesDiaAnterior(Carbon $ahora, string $tz): void
    {
        $manana = Carbon::now($tz)->addDay()->toDateString();

        $citas = Cita::whereDate('fecha', $manana)
            ->where('estado', 'pendiente')
            ->whereNotNull('id_user')
            ->where('recordatorio_pendiente_dia_anterior', false)
            ->get();

        $this->line("  📌 Pendientes para mañana ({$manana}): {$citas->count()}");

        foreach ($citas as $cita) {
            try {
                [$nombreAnfitrion, $nombreVisitante, $telefonoAnfitrion, $telefonoVisitante] = $this->resolverParticipantes($cita);

                $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                $horaIni = $this->notif->formatHora($cita->hora_inicio);
                $horaFin = $this->notif->formatHora($cita->hora_fin);

                // ── Anfitrión: confirma o cancela ──
                if ($telefonoAnfitrion) {
                    $msg = $this->notif->mensajePendienteParaAnfitrion(
                        $nombreVisitante,
                        $fecha,
                        $horaIni,
                        $horaFin,
                        $cita
                    );
                    $this->notif->enviarDirecto($telefonoAnfitrion, $msg);
                    $this->line("  📌 [día anterior] Anfitrión {$nombreAnfitrion} → {$telefonoAnfitrion}");
                }

                // ── Visitante/Proveedor: sigue sin confirmar ──
                if ($telefonoVisitante) {
                    $msg = $this->notif->mensajePendienteParaQuienAgendo(
                        $nombreAnfitrion,
                        $fecha,
                        $horaIni,
                        $horaFin,
                        $cita
                    );
                    $this->notif->enviarDirecto($telefonoVisitante, $msg);
                    $this->line("  📌 [día anterior] Visitante {$nombreVisitante} → {$telefonoVisitante}");
                }

                // ── Jefe ──
                $msgJefe = "📌 *Cita PENDIENTE para mañana sin confirmar:*\n"
                    . "👤 Anfitrión: *{$nombreAnfitrion}*\n"
                    . "👤 Visitante: *{$nombreVisitante}*\n"
                    . "📅 {$fecha}\n"
                    . "🕐 de {$horaIni} a {$horaFin}\n"
                    . ($cita->motivo ? "📋 Motivo: {$cita->motivo}\n" : '')
                    . "⚠️ La cita aún no ha sido confirmada ni cancelada.";

                $this->notif->notificarJefeSegPatrimonial($msgJefe, false);

                $cita->update(['recordatorio_pendiente_dia_anterior' => true]);

                Log::info('✅ RECORDATORIO_PENDIENTE_DIA_ANTERIOR', ['cita_id' => $cita->id]);
            } catch (\Throwable $e) {
                Log::error('❌ RECORDATORIO_PENDIENTE_DIA_ANTERIOR_ERROR', [
                    'cita_id' => $cita->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────
    // PENDIENTES: notificar el MISMO DÍA de la cita
    // Solo para citas donde el proveedor agendó al anfitrión
    // (id_visitante = el anfitrión interno, role_id = 8)
    // En la práctica: todas las pendientes del día actual sin notificar
    // ─────────────────────────────────────────────
    private function procesarPendientesMismoDia(Carbon $ahora, string $tz): void
    {
        $hoy = Carbon::now($tz)->toDateString();
        $limiteHora = Carbon::now($tz)->addHour()->format('H:i:s'); // cita debe ser en +1hr mínimo

        $citas = Cita::whereDate('fecha', $hoy)
            ->where('estado', 'pendiente')
            ->whereNotNull('id_user')
            ->whereTime('hora_inicio', '>=', $limiteHora) // ← si la cita es a las 5pm, última notif a las 4pm
            ->where('recordatorio_pendiente_mismo_dia', false)
            ->get();

        $this->line("  📌 Pendientes mismo día ({$hoy}): {$citas->count()}");

        foreach ($citas as $cita) {
            try {
                [$nombreAnfitrion, $nombreVisitante, $telefonoAnfitrion, $telefonoVisitante] = $this->resolverParticipantes($cita);

                $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                $horaIni = $this->notif->formatHora($cita->hora_inicio);
                $horaFin = $this->notif->formatHora($cita->hora_fin);

                // ── Anfitrión: última oportunidad ──
                if ($telefonoAnfitrion) {
                    $msg = "🚨 *Última oportunidad:* Tienes una cita *HOY* que sigue *sin confirmar*.\n"
                        . "👤 Visitante: *{$nombreVisitante}*\n"
                        . "📅 {$fecha}\n"
                        . "🕐 de {$horaIni} a {$horaFin}\n"
                        . ($cita->motivo ? "📋 Motivo: {$cita->motivo}\n" : '')
                        . ($cita->con_vehiculo ? "🚗 Asistirá con vehículo.\n" : '')
                        . "\n📌 *Confirma o cancela ahora.*";
                    $this->notif->enviarDirecto($telefonoAnfitrion, $msg);
                    $this->line("  🚨 [mismo día] Anfitrión {$nombreAnfitrion} → {$telefonoAnfitrion}");
                }

                // ── Visitante: ya es hoy y no han confirmado ──
                if ($telefonoVisitante) {
                    $msg = "🚨 *Atención:* Tu cita de *HOY* con *{$nombreAnfitrion}* sigue *sin confirmación*.\n"
                        . "📅 {$fecha}\n"
                        . "🕐 de {$horaIni} a {$horaFin}\n"
                        . ($cita->motivo ? "📋 Motivo: {$cita->motivo}\n" : '')
                        . ($cita->con_vehiculo ? "🚗 Asistirás con vehículo.\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil.\n" : '')
                        . "\n📌 Comunícate con *{$nombreAnfitrion}* para confirmar.";
                    $this->notif->enviarDirecto($telefonoVisitante, $msg);
                    $this->line("  🚨 [mismo día] Visitante {$nombreVisitante} → {$telefonoVisitante}");
                }

                // ── Jefe ──
                $msgJefe = "🚨 *Cita PENDIENTE sin confirmar — HOY:*\n"
                    . "👤 Anfitrión: *{$nombreAnfitrion}*\n"
                    . "👤 Visitante: *{$nombreVisitante}*\n"
                    . "📅 {$fecha}\n"
                    . "🕐 de {$horaIni} a {$horaFin}\n"
                    . ($cita->motivo ? "📋 Motivo: {$cita->motivo}\n" : '')
                    . "⚠️ No se ha confirmado ni cancelado.";

                $this->notif->notificarJefeSegPatrimonial($msgJefe, false);

                $cita->update(['recordatorio_pendiente_mismo_dia' => true]);

                Log::info('✅ RECORDATORIO_PENDIENTE_MISMO_DIA', ['cita_id' => $cita->id]);
            } catch (\Throwable $e) {
                Log::error('❌ RECORDATORIO_PENDIENTE_MISMO_DIA_ERROR', [
                    'cita_id' => $cita->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }

    // ─────────────────────────────────────────────
    // Helper: resolver nombres y teléfonos de participantes
    // ─────────────────────────────────────────────
    private function resolverParticipantes(Cita $cita): array
    {
        $anfitrionIdentity = UserFirebirdIdentity::find($cita->id_user);
        $visitanteIdentity = UserFirebirdIdentity::find($cita->id_visitante);

        $nombreAnfitrion = $anfitrionIdentity
            ? ($this->notif->obtenerNombrePorIdentity($anfitrionIdentity) ?? 'Anfitrión')
            : 'Anfitrión';

        $nombreVisitante = $visitanteIdentity
            ? ($this->notif->obtenerNombrePorIdentity($visitanteIdentity) ?? ($cita->nombre_visitante ?? 'Visitante'))
            : ($cita->nombre_visitante ?? 'Visitante');

        $telefonoAnfitrion = $anfitrionIdentity
            ? $this->notif->obtenerTelefonoPorIdentity($anfitrionIdentity)
            : null;

        $telefonoVisitante = $visitanteIdentity
            ? $this->notif->obtenerTelefonoPorIdentity($visitanteIdentity)
            : null;

        return [$nombreAnfitrion, $nombreVisitante, $telefonoAnfitrion, $telefonoVisitante];
    }

    // ─────────────────────────────────────────────
    // Procesar recordatorios confirmadas 30min / 60min
    // ─────────────────────────────────────────────
    private function procesarRecordatorios($citas, string $tipo): void
    {
        foreach ($citas as $cita) {
            try {
                [$nombreAnfitrion, $nombreVisitante, $telefonoAnfitrion, $telefonoVisitante] = $this->resolverParticipantes($cita);

                $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                $horaIni = $this->notif->formatHora($cita->hora_inicio);
                $horaFin = $this->notif->formatHora($cita->hora_fin);

                if ($telefonoAnfitrion) {
                    $msg = $this->notif->mensajeRecordatorio($tipo, $nombreVisitante, $fecha, $horaIni, $horaFin, $cita);
                    $this->notif->enviarDirecto($telefonoAnfitrion, $msg);
                    $this->line("  ✅ [{$tipo}] Anfitrión → {$telefonoAnfitrion}");
                }

                if ($telefonoVisitante) {
                    $msg = $this->notif->mensajeRecordatorio($tipo, $nombreAnfitrion, $fecha, $horaIni, $horaFin, $cita);
                    $this->notif->enviarDirecto($telefonoVisitante, $msg);
                    $this->line("  ✅ [{$tipo}] Visitante → {$telefonoVisitante}");
                }

                $msgJefe = $this->notif->mensajeRecordatorioJefe($tipo, $nombreAnfitrion, $nombreVisitante, $fecha, $horaIni, $horaFin, $cita);
                $this->notif->notificarJefeSegPatrimonial($msgJefe, false);

                $columna = $tipo === '30min' ? 'recordatorio_30min' : 'recordatorio_60min';
                $cita->update([$columna => true]);

                Log::info("✅ RECORDATORIO_{$tipo}", ['cita_id' => $cita->id]);
            } catch (\Throwable $e) {
                Log::error("❌ RECORDATORIO_ERROR", [
                    'cita_id' => $cita->id,
                    'tipo'    => $tipo,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}