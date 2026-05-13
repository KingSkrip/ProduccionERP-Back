<?php

namespace App\Services\Agenda;

use App\Models\UserFirebirdIdentity;
use App\Services\Whatsapp\UltraMSGService;
use App\Jobs\EnviarMensajeWhatsappJob;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FirebirdConnectionService;

class CitaNotificacionService
{
    protected FirebirdConnectionService $firebirdService;

    public function __construct(FirebirdConnectionService $firebirdService)
    {
        $this->firebirdService = $firebirdService;
    }

    // ─────────────────────────────────────────────
    // MENSAJES — usados tanto en Controller como en Command
    // ─────────────────────────────────────────────

    public function mensajeRecordatorio(string $tipo, string $nombreContraparte, string $fecha, string $horaIni, string $horaFin, $cita): string
    {
        $emoji     = $tipo === '30min' ? '⏰' : '🔔';
        $tiempoTxt = $tipo === '30min' ? '*30 minutos*' : '*1 hora*';
        $motivo    = $cita->motivo    ? "\n📋 Motivo: {$cita->motivo}"  : '';
        $vehiculo  = $cita->con_vehiculo
            ? "\n🚗 Asistirá con vehículo.\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil."
            : '';

        return "{$emoji} *Recordatorio:* Tu cita con *{$nombreContraparte}* comienza en {$tiempoTxt}.\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $motivo
            . $vehiculo;
    }

    public function mensajeRecordatorioJefe(string $tipo, string $nombreAnfitrion, string $nombreVisitante, string $fecha, string $horaIni, string $horaFin, $cita): string
    {
        $emoji     = $tipo === '30min' ? '⏰' : '🔔';
        $tiempoTxt = $tipo === '30min' ? '30 minutos' : '1 hora';
        $motivo    = $cita->motivo    ? "\n📋 Motivo: {$cita->motivo}"  : '';
        $vehiculo  = $cita->con_vehiculo ? "\n🚗 Asistirá con vehículo." : '';

        return "{$emoji} *Recordatorio de cita ({$tiempoTxt}):*\n"
            . "👤 Anfitrión: *{$nombreAnfitrion}*\n"
            . "👤 Visitante: *{$nombreVisitante}*\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $motivo
            . $vehiculo;
    }

    public function mensajePendienteParaAnfitrion(string $nombreQuienAgendo, string $fecha, string $horaIni, string $horaFin, $cita): string
    {
        $motivo   = $cita->motivo ? "\n📋 Motivo: {$cita->motivo}" : '';
        $vehiculo = $cita->con_vehiculo ? "\n🚗 Asistirá con vehículo." : '';

        return "⚠️ *Recordatorio:* Tienes una cita *pendiente de confirmar* con *{$nombreQuienAgendo}* para *mañana*.\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $motivo
            . $vehiculo
            . "\n\n📌 Por favor *confirma o cancela* la cita a más tardar hoy.";
    }

    public function mensajePendienteParaQuienAgendo(string $nombreAnfitrion, string $fecha, string $horaIni, string $horaFin, $cita): string
    {
        $motivo   = $cita->motivo ? "\n📋 Motivo: {$cita->motivo}" : '';
        $vehiculo = $cita->con_vehiculo ? "\n🚗 Asistirá con vehículo." : '';

        return "⚠️ *Recordatorio:* Tu cita con *{$nombreAnfitrion}* de *mañana* sigue *pendiente de confirmación*.\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $motivo
            . $vehiculo
            . "\n\n📌 Comunícate con *{$nombreAnfitrion}* para confirmar o cancelar.";
    }

    public function mensajeNuevaCitaPendiente(string $nombreContraparte, string $fecha, string $horaIni, string $horaFin, $cita, bool $soyElQueAgenda = false): string
    {
        $motivo   = $cita->motivo ? "\n📋 Motivo: {$cita->motivo}" : '';
        $vehiculo = $cita->con_vehiculo
            ? "\n🚗 Asistirá con vehículo.\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil."
            : '';
        $estadoReal = $cita->estado ?? 'pendiente';
        if ($soyElQueAgenda) {
            $sufijoPendiente = $estadoReal === 'pendiente'
                ? "\n\n📌 La cita está *pendiente*. Recuerda confirmarla antes de la fecha establecida."
                : '';
            return "✅ Tu cita con *{$nombreContraparte}* ha sido registrada para el *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                . $motivo
                . $vehiculo
                . $sufijoPendiente;
        }
        return "📅 *{$nombreContraparte}* ha agendado una cita contigo para el *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
            . $motivo
            . $vehiculo
            . "\n\n📌 Recuerda *confirmar o cancelar* esta cita a más tardar un día antes de la fecha establecida.";
    }

    // ─────────────────────────────────────────────
    // ENVÍO — con Job (para Controllers con Request)
    // ─────────────────────────────────────────────

    public function enviarConJob(string $telefono, string $mensaje, int $delayMinutos = 0): void
    {
        EnviarMensajeWhatsappJob::dispatch($telefono, $mensaje)
            ->delay(now()->addMinutes($delayMinutos))
            ->onQueue('whatsapp');

        Log::info('📨 WhatsApp encolado', [
            'telefono'      => $telefono,
            'delay_minutos' => $delayMinutos,
        ]);
    }

    // ─────────────────────────────────────────────
    // ENVÍO — directo (para Commands / Artisan)
    // ─────────────────────────────────────────────

    public function enviarDirecto(string $telefono, string $mensaje): void
    {
        $whatsapp = new UltraMSGService();
        $whatsapp->sendMessage($telefono, $mensaje);

        Log::info('📨 WhatsApp enviado directo', ['telefono' => $telefono]);
    }

    // ─────────────────────────────────────────────
    // TELÉFONO — por identity
    // ─────────────────────────────────────────────

    public function obtenerTelefonoPorIdentity(UserFirebirdIdentity $identity): ?string
    {
        if ($identity->firebird_tb_clave !== null) {
            try {
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    return $tbRow->TELEFONO ?? $tbRow->TEL ?? $tbRow->TEL_CELULAR ?? $tbRow->CELULAR ?? $tbRow->TEL_PARTICULAR ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('TELEFONO TB error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_clie_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM CLIE03 WHERE CLAVE = ?",
                    [$identity->firebird_clie_clave]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('TELEFONO CLIE03 error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_vend_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM VEND03 WHERE CVE_VEND = ?",
                    [$identity->firebird_vend_clave]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('TELEFONO VEND03 error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_prov_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM PROV03 WHERE TRIM(CLAVE) = ?",
                    [trim((string) $identity->firebird_prov_clave)]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('TELEFONO PROV03 error', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // NOMBRE — por identity
    // ─────────────────────────────────────────────

    public function obtenerNombrePorIdentity(UserFirebirdIdentity $identity): ?string
    {
        if ($identity->firebird_tb_clave !== null) {
            try {
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                return $tbRow?->NOMBRE ?? null;
            } catch (\Throwable $e) {
                Log::error('NOMBRE TB error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_clie_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne("SELECT NOMBRE FROM CLIE03 WHERE CLAVE = ?", [$identity->firebird_clie_clave]);
                return $row?->NOMBRE ?? null;
            } catch (\Throwable $e) {
                Log::error('NOMBRE CLIE03 error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_vend_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne("SELECT NOMBRE FROM VEND03 WHERE CVE_VEND = ?", [$identity->firebird_vend_clave]);
                return $row?->NOMBRE ?? null;
            } catch (\Throwable $e) {
                Log::error('NOMBRE VEND03 error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_prov_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT NOMBRE FROM PROV03 WHERE TRIM(CLAVE) = ?",
                    [trim((string) $identity->firebird_prov_clave)]
                );
                return $row?->NOMBRE ?? null;
            } catch (\Throwable $e) {
                Log::error('NOMBRE PROV03 error', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // NOTIFICAR JEFE SEG PATRIMONIAL
    // ─────────────────────────────────────────────

    public function notificarJefeSegPatrimonial(string $mensaje, bool $usarJob = true, int $delayMinutos = 0): void
    {
        try {
            $segPatrId     = (int) env('SEG_PATR_ID');
            $segPatrNombre = trim((string) env('SEG_PATR', ''));

            if (!$segPatrId || !$segPatrNombre) {
                Log::warning('⚠️ SEG_PATR o SEG_PATR_ID no configurados');
                return;
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', $segPatrId)->first();
            if (!$identity) {
                Log::warning('⚠️ SEG_PATR identity no encontrada');
                return;
            }

            $telefono = null;

            if ($identity->firebird_tb_clave !== null) {
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    $nombreEnv        = mb_strtoupper(trim($segPatrNombre));
                    $nombreTbCompleto = mb_strtoupper(trim(
                        trim($tbRow->NOMBRE ?? '') . ' ' .
                            trim($tbRow->AP_PAT_ ?? '') . ' ' .
                            trim($tbRow->AP_MAT_ ?? '')
                    ));
                    $nombreTbSolo = mb_strtoupper(trim($tbRow->NOMBRE ?? ''));

                    $coincide = ($nombreTbSolo === $nombreEnv)
                        || str_starts_with($nombreTbCompleto, $nombreEnv)
                        || str_starts_with($nombreEnv, $nombreTbSolo);

                    if (!$coincide) {
                        Log::warning('⚠️ SEG_PATR nombre no coincide', ['env' => $nombreEnv, 'tb' => $nombreTbCompleto]);
                        return;
                    }

                    $telefono = $tbRow->TELEFONO ?? $tbRow->TEL ?? $tbRow->TEL_CELULAR ?? $tbRow->CELULAR ?? $tbRow->TEL_PARTICULAR ?? null;
                }
            }

            if (!$telefono) {
                Log::warning('⚠️ SEG_PATR sin teléfono', ['identity_id' => $identity->id]);
                return;
            }

            if ($usarJob) {
                $this->enviarConJob($telefono, $mensaje, $delayMinutos);
            } else {
                $this->enviarDirecto($telefono, $mensaje);
            }

            Log::info('✅ SEG_PATR notificado', ['telefono' => $telefono]);
        } catch (\Throwable $e) {
            Log::error('❌ SEG_PATR_NOTIFY_ERROR', ['error' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────
    // FORMATO DE HORA
    // ─────────────────────────────────────────────

    public function formatHora($hora): string
    {
        $c      = $hora instanceof \Carbon\Carbon ? $hora : Carbon::parse($hora);
        $sufijo = $c->format('A') === 'AM' ? 'am' : 'pm';
        return $c->format('g:i') . ' ' . $sufijo;
    }
}