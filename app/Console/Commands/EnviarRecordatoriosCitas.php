<?php

namespace App\Console\Commands;

use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Models\Firebird\Users;
use App\Services\UserService;
use App\Services\Whatsapp\UltraMSGService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EnviarRecordatoriosCitas extends Command
{
    protected $signature   = 'citas:recordatorios';
    protected $description = 'Envía recordatorios de WhatsApp 30 min y 1 hora antes de cada cita';

    public function handle(): void
    {
        $ahora        = Carbon::now();
        $en30min      = $ahora->copy()->addMinutes(30);
        $en60min      = $ahora->copy()->addMinutes(60);
        $ventana      = 5; // ±5 minutos de tolerancia para no perder ejecuciones

        $this->info("⏰ Ejecutando recordatorios — {$ahora->format('Y-m-d H:i:s')}");

        // ── Citas que empiezan en ~30 minutos ──
      $citas30 = $this->getCitasEnRango($en30min, $ventana, '30min');
$citas60 = $this->getCitasEnRango($en60min, $ventana, '60min');

        $this->procesarCitas($citas30, '30min');
        $this->procesarCitas($citas60, '60min');

        $this->info("✅ Recordatorios enviados. 30min: {$citas30->count()} | 60min: {$citas60->count()}");
    }

    // ─────────────────────────────────────────────
    // Obtener citas cuya hora_inicio esté dentro
    // del rango: [$objetivo - $ventana, $objetivo + $ventana]
    // Solo citas pendientes o confirmadas (no canceladas)
    // ─────────────────────────────────────────────
private function getCitasEnRango(Carbon $objetivo, int $ventanaMinutos, string $tipo)
{
    $desde = $objetivo->copy()->subMinutes($ventanaMinutos)->format('H:i:s');
    $hasta = $objetivo->copy()->addMinutes($ventanaMinutos)->format('H:i:s');
    $hoy   = Carbon::now()->toDateString();

    $columna = $tipo === '30min' ? 'recordatorio_30min' : 'recordatorio_60min';

    return Cita::whereDate('fecha', $hoy)
        ->whereTime('hora_inicio', '>=', $desde)
        ->whereTime('hora_inicio', '<=', $hasta)
        ->whereIn('estado', ['pendiente', 'confirmada'])
        ->whereNotNull('id_user')
        ->where($columna, false) // ✅ solo las que NO han sido notificadas
        ->get();
}

    // ─────────────────────────────────────────────
    // Procesar y enviar mensaje por cada cita
    // ─────────────────────────────────────────────
    private function procesarCitas($citas, string $tipo): void
    {
        foreach ($citas as $cita) {
            try {
                // ── Obtener identity del usuario ──
                $identity = UserFirebirdIdentity::where('id', $cita->id_user)->first();

                if (!$identity) {
                    Log::warning("⚠️ RECORDATORIO: Sin identity para id_user={$cita->id_user}");
                    continue;
                }

                // ── Obtener teléfono según tipo de usuario ──
                $telefono = $this->obtenerTelefonoPorIdentity($identity);

                if (!$telefono) {
                    Log::warning("⚠️ RECORDATORIO: Sin teléfono para id_user={$cita->id_user}");
                    continue;
                }

                // ── Obtener nombre del usuario ──
                $nombre = $this->obtenerNombrePorIdentity($identity);

                // ── Formatear fecha y horas ──
                $fecha   = Carbon::parse($cita->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
                $horaIni = $this->formatHora($cita->hora_inicio);
                $horaFin = $this->formatHora($cita->hora_fin);

                // ── Construir mensaje según el tipo de recordatorio ──
                $mensaje = $this->construirMensaje($tipo, $nombre, $fecha, $horaIni, $horaFin, $cita);

                // ── Enviar WhatsApp ──
                $whatsapp  = new UltraMSGService();
                $resultado = $whatsapp->sendMessage($telefono, $mensaje);
                
$columna = $tipo === '30min' ? 'recordatorio_30min' : 'recordatorio_60min';
$cita->update([$columna => true]);
                Log::info("✅ RECORDATORIO_{$tipo} enviado", [
                    'cita_id'  => $cita->id,
                    'id_user'  => $cita->id_user,
                    'telefono' => $telefono,
                    'enviado'  => $resultado['success'] ?? false,
                ]);

                $this->line("  ✅ [{$tipo}] Cita #{$cita->id} → {$telefono}");
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
    // Construir el mensaje según si son 30 o 60 min
    // ─────────────────────────────────────────────
    private function construirMensaje(
        string $tipo,
        ?string $nombre,
        string $fecha,
        string $horaIni,
        string $horaFin,
        Cita $cita
    ): string {
        $saludo    = $nombre ? "Hola {$nombre}." : "Hola.";
        $visitante = $cita->nombre_visitante ? "\n👤 Visitante: {$cita->nombre_visitante}" : '';
        $motivo    = $cita->motivo           ? "\n📋 Motivo: {$cita->motivo}"             : '';

        if ($tipo === '30min') {
            return "{$saludo}\n"
                . "⏰ *Recordatorio:* Tu cita comienza en *30 minutos*.\n"
                . "📅 {$fecha}\n"
                . "🕐 de {$horaIni} a {$horaFin}"
                . $visitante
                . $motivo
                . "\n\n¡Prepárate a tiempo!";
        }

        // 60 min
        return "{$saludo}\n"
            . "🔔 *Recordatorio:* Tienes una cita en *1 hora*.\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $visitante
            . $motivo;
    }

    // ─────────────────────────────────────────────
    // Obtener teléfono desde Firebird según tipo
    // ─────────────────────────────────────────────
    private function obtenerTelefonoPorIdentity(UserFirebirdIdentity $identity): ?string
    {
        // Empleado → TB
        if ($identity->firebird_tb_clave !== null) {
            try {
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $firebirdNoi = new \App\Services\FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tb    = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string)$row->CLAVE));
                $tbRow = $tb[$tbClave] ?? null;

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

        // Cliente → CLIE03
        if ($identity->firebird_clie_clave !== null) {
            try {
                $row = $this->firebirdConn()->selectOne(
                    "SELECT * FROM CLIE03 WHERE CLAVE = ?",
                    [$identity->firebird_clie_clave]
                );
                return $row->TELEFONO ?? $row->TEL ?? $row->CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('RECORDATORIO CLIE03 error', ['error' => $e->getMessage()]);
            }
        }

        // Vendedor → VEND03
        if ($identity->firebird_vend_clave !== null) {
            try {
                $row = $this->firebirdConn()->selectOne(
                    "SELECT * FROM VEND03 WHERE CVE_VEND = ?",
                    [$identity->firebird_vend_clave]
                );
                return $row->TELEFONO ?? $row->TEL ?? $row->CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('RECORDATORIO VEND03 error', ['error' => $e->getMessage()]);
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // Obtener nombre desde Firebird
    // ─────────────────────────────────────────────
    private function obtenerNombrePorIdentity(UserFirebirdIdentity $identity): ?string
    {
        $usuario = Users::find($identity->firebird_user_clave);
        return $usuario->NOMBRE ?? null;
    }

    // ─────────────────────────────────────────────
    // Formato hora → "8:00 am" / "3:30 pm"
    // ─────────────────────────────────────────────
    private function formatHora(string $hora): string
    {
        $c      = Carbon::parse($hora);
        $sufijo = $c->format('A') === 'AM' ? 'am' : 'pm';
        return $c->format('g:i') . ' ' . $sufijo;
    }

    // ─────────────────────────────────────────────
    // Conexión Firebird producción (igual que el controller)
    // ─────────────────────────────────────────────
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

        \Illuminate\Support\Facades\DB::purge('firebird_produccion');

        return \Illuminate\Support\Facades\DB::connection('firebird_produccion');
    }
}