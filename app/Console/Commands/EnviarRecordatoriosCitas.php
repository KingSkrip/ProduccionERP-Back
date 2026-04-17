<?php

namespace App\Console\Commands;

use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Models\Firebird\Users;
use App\Services\FirebirdEmpresaManualService;
use App\Services\Whatsapp\UltraMSGService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnviarRecordatoriosCitas extends Command
{
    protected $signature   = 'citas:recordatorios';
    protected $description = 'Envía recordatorios de WhatsApp 30 min y 1 hora antes de cada cita';

    public function handle(): void
    {
        // ✅ Usar timezone de México explícitamente
        $ahora   = Carbon::now(env('APP_TIMEZONE', 'America/Mexico_City'));
        $en30min = $ahora->copy()->addMinutes(30);
        $en60min = $ahora->copy()->addMinutes(60);
        $ventana = 2; // ±2 minutos (el scheduler corre cada minuto, es suficiente)

        $this->info("⏰ Ejecutando recordatorios — {$ahora->format('Y-m-d H:i:s')} (Mexico_City)");

        $citas30 = $this->getCitasEnRango($en30min, $ventana, '30min');
        $citas60 = $this->getCitasEnRango($en60min, $ventana, '60min');

        $this->procesarCitas($citas30, '30min');
        $this->procesarCitas($citas60, '60min');

        $this->info("✅ Recordatorios enviados. 30min: {$citas30->count()} | 60min: {$citas60->count()}");
    }

    private function getCitasEnRango(Carbon $objetivo, int $ventanaMinutos, string $tipo)
    {
        $desde   = $objetivo->copy()->subMinutes($ventanaMinutos)->format('H:i:s');
        $hasta   = $objetivo->copy()->addMinutes($ventanaMinutos)->format('H:i:s');
        $hoy     = Carbon::now(env('APP_TIMEZONE', 'America/Mexico_City'))->toDateString();
        $columna = $tipo === '30min' ? 'recordatorio_30min' : 'recordatorio_60min';

        $this->line("  🔍 [{$tipo}] Buscando citas el {$hoy} entre {$desde} y {$hasta}");

        return Cita::whereDate('fecha', $hoy)
            ->whereTime('hora_inicio', '>=', $desde)
            ->whereTime('hora_inicio', '<=', $hasta)
            ->whereIn('estado', ['pendiente', 'confirmada'])
            ->whereNotNull('id_user')
            ->where($columna, false)
            ->get();
    }

    private function procesarCitas($citas, string $tipo): void
    {
        foreach ($citas as $cita) {
            try {
                $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                $horaIni = $this->formatHora($cita->hora_inicio);
                $horaFin = $this->formatHora($cita->hora_fin);

                // ── Identities ──
                $anfitrionIdentity = UserFirebirdIdentity::find($cita->id_user);
                $visitanteIdentity = UserFirebirdIdentity::find($cita->id_visitante);

                $nombreAnfitrion = $this->obtenerNombrePorIdentity($anfitrionIdentity) ?? 'Anfitrión';
                $nombreVisitante = $this->obtenerNombrePorIdentity($visitanteIdentity)
                    ?? $cita->nombre_visitante
                    ?? 'Visitante';

                $telefonoAnfitrion = $anfitrionIdentity ? $this->obtenerTelefonoPorIdentity($anfitrionIdentity) : null;
                $telefonoVisitante = $visitanteIdentity ? $this->obtenerTelefonoPorIdentity($visitanteIdentity) : null;

                $motivo     = $cita->motivo ? "\n📋 Motivo: {$cita->motivo}" : '';
                $vehiculo   = $cita->con_vehiculo ? "\n🚗 Asistirá con vehículo." : '';
                $tipoTexto  = $tipo === '30min' ? '*30 minutos*' : '*1 hora*';
                $emoji      = $tipo === '30min' ? '⏰' : '🔔';

                // ── Mensaje ANFITRIÓN ──
                $mensajeAnfitrion = "{$emoji} *Recordatorio:* Tu cita con *{$nombreVisitante}* comienza en {$tipoTexto}.\n"
                    . "📅 {$fecha}\n"
                    . "🕐 de {$horaIni} a {$horaFin}"
                    . $motivo
                    . $vehiculo;

                // ── Mensaje VISITANTE ──
                $mensajeVisitante = "{$emoji} *Recordatorio:* Tu cita con *{$nombreAnfitrion}* comienza en {$tipoTexto}.\n"
                    . "📅 {$fecha}\n"
                    . "🕐 de {$horaIni} a {$horaFin}"
                    . $motivo
                    . $vehiculo
                    . ($cita->con_vehiculo ? "\n\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil." : '');

                // ── Mensaje JEFE SEG PATRIMONIAL ──
                $mensajeJefe = "{$emoji} *Recordatorio de cita próxima ({$tipo}):*\n"
                    . "👤 Anfitrión: *{$nombreAnfitrion}*\n"
                    . "👤 Visitante: *{$nombreVisitante}*\n"
                    . "📅 {$fecha}\n"
                    . "🕐 de {$horaIni} a {$horaFin}"
                    . $motivo
                    . $vehiculo;

                $whatsapp = new UltraMSGService();

                // ── Enviar al ANFITRIÓN ──
                if ($telefonoAnfitrion) {
                    $whatsapp->sendMessage($telefonoAnfitrion, $mensajeAnfitrion);
                    $this->line("  ✅ [{$tipo}] Anfitrión #{$cita->id_user} → {$telefonoAnfitrion}");
                } else {
                    Log::warning("⚠️ RECORDATORIO: Sin teléfono para anfitrión id={$cita->id_user}");
                }

                // ── Enviar al VISITANTE ──
                if ($telefonoVisitante) {
                    $whatsapp->sendMessage($telefonoVisitante, $mensajeVisitante);
                    $this->line("  ✅ [{$tipo}] Visitante #{$cita->id_visitante} → {$telefonoVisitante}");
                } else {
                    Log::warning("⚠️ RECORDATORIO: Sin teléfono para visitante id={$cita->id_visitante}");
                }

                // ── Enviar al JEFE DE SEG PATRIMONIAL ──
                $this->notificarJefeSegPatrimonial($mensajeJefe);

                // ── Marcar como notificada ──
                $columna = $tipo === '30min' ? 'recordatorio_30min' : 'recordatorio_60min';
                $cita->update([$columna => true]);

                Log::info("✅ RECORDATORIO_{$tipo} enviado", [
                    'cita_id'            => $cita->id,
                    'telefono_anfitrion' => $telefonoAnfitrion,
                    'telefono_visitante' => $telefonoVisitante,
                ]);

            } catch (\Throwable $e) {
                Log::error("❌ RECORDATORIO_ERROR", [
                    'cita_id' => $cita->id,
                    'tipo'    => $tipo,
                    'error'   => $e->getMessage(),
                ]);
                $this->error("  ❌ Cita #{$cita->id}: {$e->getMessage()}");
            }
        }
    }

    // ─────────────────────────────────────────────
    // Notificar al Jefe de Seguridad Patrimonial
    // ─────────────────────────────────────────────
    private function notificarJefeSegPatrimonial(string $mensaje): void
    {
        try {
            $segPatrId     = (int) env('SEG_PATR_ID');
            $segPatrNombre = trim((string) env('SEG_PATR', ''));

            if (!$segPatrId || !$segPatrNombre) {
                Log::warning('⚠️ SEG_PATR o SEG_PATR_ID no configurados en .env');
                return;
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', $segPatrId)->first();
            if (!$identity) {
                Log::warning('⚠️ SEG_PATR: identity no encontrada', ['firebird_user_clave' => $segPatrId]);
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
                    $nombreEnvNormalizado = mb_strtoupper(trim($segPatrNombre));
                    $nombreTbCompleto     = mb_strtoupper(trim(
                        trim($tbRow->NOMBRE ?? '') . ' ' .
                        trim($tbRow->AP_PAT_ ?? '') . ' ' .
                        trim($tbRow->AP_MAT_ ?? '')
                    ));
                    $nombreTbSolo = mb_strtoupper(trim($tbRow->NOMBRE ?? ''));

                    $coincide = ($nombreTbSolo === $nombreEnvNormalizado)
                        || str_starts_with($nombreTbCompleto, $nombreEnvNormalizado)
                        || str_starts_with($nombreEnvNormalizado, $nombreTbSolo);

                    if (!$coincide) {
                        Log::warning('⚠️ SEG_PATR: nombre en .env no coincide con TB', [
                            'env' => $nombreEnvNormalizado,
                            'tb'  => $nombreTbCompleto,
                        ]);
                        return;
                    }

                    $telefono = $tbRow->TELEFONO
                        ?? $tbRow->TEL
                        ?? $tbRow->TEL_CELULAR
                        ?? $tbRow->CELULAR
                        ?? $tbRow->TEL_PARTICULAR
                        ?? null;
                }
            }

            if (!$telefono) {
                Log::warning('⚠️ SEG_PATR: sin teléfono encontrado', ['identity_id' => $identity->id]);
                return;
            }

            $whatsapp = new UltraMSGService();
            $whatsapp->sendMessage($telefono, $mensaje);

            Log::info('✅ SEG_PATR notificado (recordatorio)', [
                'identity_id' => $identity->id,
                'telefono'    => $telefono,
            ]);

        } catch (\Throwable $e) {
            Log::error('❌ SEG_PATR_RECORDATORIO_ERROR', ['error' => $e->getMessage()]);
        }
    }

    private function obtenerTelefonoPorIdentity(UserFirebirdIdentity $identity): ?string
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
                    return $tbRow->TELEFONO
                        ?? $tbRow->TEL
                        ?? $tbRow->TEL_CELULAR
                        ?? $tbRow->CELULAR
                        ?? $tbRow->TEL_PARTICULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('RECORDATORIO TB error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_clie_clave !== null) {
            try {
                $row = $this->firebirdConn()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM CLIE03 WHERE CLAVE = ?",
                    [$identity->firebird_clie_clave]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('RECORDATORIO CLIE03 error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_vend_clave !== null) {
            try {
                $row = $this->firebirdConn()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM VEND03 WHERE CVE_VEND = ?",
                    [$identity->firebird_vend_clave]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('RECORDATORIO VEND03 error', ['error' => $e->getMessage()]);
            }
        }

        if ($identity->firebird_prov_clave !== null) {
            try {
                $row = $this->firebirdConn()->selectOne(
                    "SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM PROV03 WHERE TRIM(CLAVE) = ?",
                    [trim((string) $identity->firebird_prov_clave)]
                );
                return $row?->TELEFONO ?? $row?->TEL ?? $row?->CELULAR ?? $row?->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('RECORDATORIO PROV03 error', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    private function obtenerNombrePorIdentity(?UserFirebirdIdentity $identity): ?string
    {
        if (!$identity) return null;
        $usuario = Users::find($identity->firebird_user_clave);
        return $usuario?->NOMBRE ?? null;
    }

    private function formatHora(string $hora): string
    {
        $c      = Carbon::parse($hora);
        $sufijo = $c->format('A') === 'AM' ? 'am' : 'pm';
        return $c->format('g:i') . ' ' . $sufijo;
    }

    private function firebirdConn(): \Illuminate\Database\Connection
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
}