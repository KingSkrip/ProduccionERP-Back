<?php

namespace App\Services;

use App\Jobs\EnviarMensajeWhatsappJob;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FirebirdConnectionService;

class MailboxNotificacionService
{
    private static int $queueIndex = 0;
    protected FirebirdConnectionService $firebirdService;
    
    public function __construct(FirebirdConnectionService $firebirdService)
    {
        $this->firebirdService = $firebirdService;
    }
    // ─────────────────────────────────────────────
    // MENSAJES
    // ─────────────────────────────────────────────

    public function mensajeTicketIniciado(string $nombreQuienInicio, string $tituloWorkorder): string
    {
        $fecha = Carbon::now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $hora  = Carbon::now()->format('g:i') . ' ' . (Carbon::now()->format('A') === 'AM' ? 'am' : 'pm');

        return "*Ticket iniciado*\n\n"
            . "*{$nombreQuienInicio}* ha iniciado el ticket: *{$tituloWorkorder}*\n\n"
            . "El día {$fecha} a las {$hora}\n\n";
    }

    public function mensajeTicketIniciadoPropio(string $tituloWorkorder): string
    {
        $fecha = Carbon::now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $hora  = Carbon::now()->format('g:i') . ' ' . (Carbon::now()->format('A') === 'AM' ? 'am' : 'pm');

        return "*Has iniciado el ticket*\n\n"
            . "Ticket: *{$tituloWorkorder}*\n\n"
            . "El día {$fecha} a las {$hora}\n\n";
    }

    public function mensajeTicketFinalizado(string $nombreQuienFinalizo, string $tituloWorkorder): string
    {
        $fecha = Carbon::now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $hora  = Carbon::now()->format('g:i') . ' ' . (Carbon::now()->format('A') === 'AM' ? 'am' : 'pm');
        return "*Ticket finalizado*\n\n"
            . "*{$nombreQuienFinalizo}* ha finalizado el ticket: *{$tituloWorkorder}*\n\n"
            . "El día {$fecha} a las {$hora}\n\n";
    }

    public function mensajeTicketFinalizadoPropio(string $tituloWorkorder): string
    {
        $fecha = Carbon::now()->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $hora  = Carbon::now()->format('g:i') . ' ' . (Carbon::now()->format('A') === 'AM' ? 'am' : 'pm');

        return "*Has finalizado el ticket*\n\n"
            . "Ticket: *{$tituloWorkorder}*\n\n"
            . "El día {$fecha} a las {$hora}\n\n";
    }

    // ─────────────────────────────────────────────
    // ENVÍO CON JOB (cola por minutos)
    // ─────────────────────────────────────────────

    public function enviarConJob(string $telefono, string $mensaje, int $delayMinutos = 0): void
    {
        EnviarMensajeWhatsappJob::dispatch($telefono, $mensaje)
            ->delay(now()->addMinutes($delayMinutos))
            ->onQueue('whatsapp');

        Log::info('📨 [Mailbox] WhatsApp encolado', [
            'telefono'      => $telefono,
            'delay_minutos' => $delayMinutos,
        ]);
    }

    // ─────────────────────────────────────────────
    // OBTENER TELÉFONO POR IDENTITY
    // ─────────────────────────────────────────────

    public function obtenerTelefonoPorIdentity(UserFirebirdIdentity $identity): ?string
    {
        // EMPLEADO → TB (NOI)
        if ($identity->firebird_tb_clave !== null) {
            try {
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    return $tbRow->TELEFONO
                        ?? $tbRow->TEL
                        ?? $tbRow->TEL_CELULAR
                        ?? $tbRow->CELULAR
                        ?? $tbRow->TEL_PARTICULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('❌ [Mailbox] TELEFONO TB error', ['error' => $e->getMessage()]);
            }
        }

        // CLIENTE → CLIE03
        if ($identity->firebird_clie_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM CLIE03 WHERE CLAVE = ?",
                    [$identity->firebird_clie_clave]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('❌ [Mailbox] TELEFONO CLIE03 error', ['error' => $e->getMessage()]);
            }
        }

        // VENDEDOR → VEND03
        if ($identity->firebird_vend_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM VEND03 WHERE CVE_VEND = ?",
                    [$identity->firebird_vend_clave]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('❌ [Mailbox] TELEFONO VEND03 error', ['error' => $e->getMessage()]);
            }
        }

        // PROVEEDOR → PROV03
        if ($identity->firebird_prov_clave !== null) {
            try {
                $row = $this->firebirdService->getProductionConnection()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM PROV03 WHERE TRIM(CLAVE) = ?",
                    [trim((string) $identity->firebird_prov_clave)]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('❌ [Mailbox] TELEFONO PROV03 error', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // OBTENER NOMBRE POR IDENTITY
    // ─────────────────────────────────────────────

    public function obtenerNombrePorIdentity(UserFirebirdIdentity $identity): string
    {
        return $identity->firebirdUser?->NOMBRE ?? 'Usuario';
    }

    // ─────────────────────────────────────────────
    // NOTIFICAR TICKET INICIADO
    // ─────────────────────────────────────────────
    public function notificarTicketIniciado($workorder, int $quienInicioIdentityId): void
    {
        self::$queueIndex = 0;

        $quienInicioIdentity = UserFirebirdIdentity::find($quienInicioIdentityId);
        if (!$quienInicioIdentity) {
            Log::warning('[Mailbox] notificarTicketIniciado: identity no encontrada', ['id' => $quienInicioIdentityId]);
            return;
        }

        $nombreQuienInicio = $this->obtenerNombrePorIdentity($quienInicioIdentity);
        $titulo            = $workorder->titulo ?? '(Sin asunto)';

        // ── EMISOR del workorder (de_id) ──
        $emisorIdentity = UserFirebirdIdentity::find($workorder->de_id);
        if ($emisorIdentity) {
            $telefono = $this->obtenerTelefonoPorIdentity($emisorIdentity);
            if ($telefono) {

                $mensaje = ($emisorIdentity->id === $quienInicioIdentityId)
                    ? $this->mensajeTicketIniciadoPropio($titulo)
                    : $this->mensajeTicketIniciado($nombreQuienInicio, $titulo);

                $this->enviarConJob($telefono, $mensaje, self::$queueIndex++);
            }
        }

        // ── RECEPTOR principal (para_id) ──
        if ($workorder->para_id) {
            $receptorIdentity = UserFirebirdIdentity::find($workorder->para_id);
            if ($receptorIdentity && $receptorIdentity->id !== ($emisorIdentity?->id)) {
                $telefono = $this->obtenerTelefonoPorIdentity($receptorIdentity);
                if ($telefono) {
                    $mensaje = ($receptorIdentity->id === $quienInicioIdentityId)
                        ? $this->mensajeTicketIniciadoPropio($titulo)
                        : $this->mensajeTicketIniciado($nombreQuienInicio, $titulo);

                    $this->enviarConJob($telefono, $mensaje, self::$queueIndex++);
                }
            }
        }

        // ── PARTICIPANTES (task_participants) ──
        $participantes = $workorder->taskParticipants ?? collect();
        $yaNotificados = array_filter([
            $emisorIdentity?->id,
            $workorder->para_id,
        ]);

        foreach ($participantes as $participante) {
            $participanteIdentity = UserFirebirdIdentity::find($participante->user_id);
            if (!$participanteIdentity) continue;

            // Evitar duplicados
            if (in_array($participanteIdentity->id, $yaNotificados)) continue;

            $telefono = $this->obtenerTelefonoPorIdentity($participanteIdentity);
            if (!$telefono) continue;

            $mensaje = ($participanteIdentity->id === $quienInicioIdentityId)
                ? $this->mensajeTicketIniciadoPropio($titulo)
                : $this->mensajeTicketIniciado($nombreQuienInicio, $titulo);

            $this->enviarConJob($telefono, $mensaje, self::$queueIndex++);
            $yaNotificados[] = $participanteIdentity->id;
        }
    }




    public function notificarTicketFinalizado($workorder, int $quienFinalizoIdentityId): void
    {
        self::$queueIndex = 0;

        $quienFinalizoIdentity  = UserFirebirdIdentity::find($quienFinalizoIdentityId);
        if (!$quienFinalizoIdentity) {
            Log::warning('[Mailbox] notificarTicketFinalizado: identity no encontrada', ['id' => $quienFinalizoIdentityId]);
            return;
        }

        $nombreQuienFinalizo = $this->obtenerNombrePorIdentity($quienFinalizoIdentity);
        $titulo            = $workorder->titulo ?? '(Sin asunto)';

        // ── EMISOR del workorder (de_id) ──
        $emisorIdentity = UserFirebirdIdentity::find($workorder->de_id);
        if ($emisorIdentity) {
            $telefono = $this->obtenerTelefonoPorIdentity($emisorIdentity);
            if ($telefono) {

                $mensaje = ($emisorIdentity->id === $quienFinalizoIdentityId)
                    ? $this->mensajeTicketFinalizadoPropio($titulo)
                    : $this->mensajeTicketFinalizado($nombreQuienFinalizo, $titulo);

                $this->enviarConJob($telefono, $mensaje, self::$queueIndex++);
            }
        }

        // ── RECEPTOR principal (para_id) ──
        if ($workorder->para_id) {
            $receptorIdentity = UserFirebirdIdentity::find($workorder->para_id);
            if ($receptorIdentity && $receptorIdentity->id !== ($emisorIdentity?->id)) {
                $telefono = $this->obtenerTelefonoPorIdentity($receptorIdentity);
                if ($telefono) {
                    $mensaje = ($receptorIdentity->id === $quienFinalizoIdentityId)
                        ? $this->mensajeTicketFinalizadoPropio($titulo)
                        : $this->mensajeTicketFinalizado($nombreQuienFinalizo, $titulo);

                    $this->enviarConJob($telefono, $mensaje, self::$queueIndex++);
                }
            }
        }

        // ── PARTICIPANTES (task_participants) ──
        $participantes = $workorder->taskParticipants ?? collect();
        $yaNotificados = array_filter([
            $emisorIdentity?->id,
            $workorder->para_id,
        ]);

        foreach ($participantes as $participante) {
            $participanteIdentity = UserFirebirdIdentity::find($participante->user_id);
            if (!$participanteIdentity) continue;

            // Evitar duplicados
            if (in_array($participanteIdentity->id, $yaNotificados)) continue;

            $telefono = $this->obtenerTelefonoPorIdentity($participanteIdentity);
            if (!$telefono) continue;

            $mensaje = ($participanteIdentity->id === $quienFinalizoIdentityId)
                ? $this->mensajeTicketFinalizadoPropio($titulo)
                : $this->mensajeTicketFinalizado($nombreQuienFinalizo, $titulo);

            $this->enviarConJob($telefono, $mensaje, self::$queueIndex++);
            $yaNotificados[] = $participanteIdentity->id;
        }
    }

    // ─────────────────────────────────────────────
    // CONEXIÓN FIREBIRD
    // ─────────────────────────────────────────────

}