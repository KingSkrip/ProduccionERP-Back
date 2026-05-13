<?php

namespace App\Services\Agenda;

use App\Jobs\EnviarMensajeWhatsappJob;
use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JuntaNotificacionService
{

    public function __construct(
        private FirebirdEmpresaManualService $firebird,  // ← esto puede fallar
        private UserService $userService,
    ) {}

    // ─────────────────────────────────────────────
    // MENSAJES
    // ─────────────────────────────────────────────

    /**
     * Mensaje para el ORGANIZADOR cuando crea una junta.
     * "Agendaste una junta con Fulano, Mengano. Asunto: X"
     */
    public function mensajeCreacionParaOrganizador(
        string $nombreOrganizador,
        array  $nombresParticipantes,
        string $fecha,
        string $horaIni,
        string $horaFin,
        ?string $asunto = null,
        ?string $sala   = null,
        ?string $notas  = null,
    ): string {
        $lista     = $this->listaParticipantes($nombresParticipantes);
        $asuntoTxt = $asunto ? "\n📋 Asunto: {$asunto}" : '';
        $salaTxt   = $sala   ? "\n📍 Sala: " . getSalaLabel($sala) : '';
        $notasTxt  = $notas  ? "\n📝 Notas: {$notas}"   : '';

        return "✅ *Junta agendada*\n"
            . "👤 Participantes: {$lista}\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $salaTxt
            . $asuntoTxt
            . $notasTxt;
    }

    /**
     * Mensaje para cada PARTICIPANTE cuando es invitado a una junta.
     * Incluye solicitud de confirmación de asistencia.
     */
    public function mensajeInvitacionParaParticipante(
        string  $nombreOrganizador,
        string  $nombreParticipante,
        string  $fecha,
        string  $horaIni,
        string  $horaFin,
        ?string $asunto = null,
        ?string $sala   = null,
        ?string $notas  = null,
    ): string {
        $asuntoTxt = $asunto ? "\nAsunto: {$asunto}" : '';
        $salaTxt   = $sala   ? "\n📍 Sala: " . getSalaLabel($sala) : '';
        $notasTxt  = $notas  ? "\nNotas: {$notas}"   : '';

        return "*{$nombreOrganizador}* te ha invitado a una junta.\n"
            . "{$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $salaTxt
            . $asuntoTxt
            . $notasTxt
            . "\n\n✋ *Por favor confirma tu asistencia* respondiendo a esta junta en la app.";
    }

    /**
     * Mensaje para el ORGANIZADOR cuando se edita la junta.
     */
    public function mensajeEdicionParaOrganizador(
        array   $nombresParticipantes,
        string  $fecha,
        string  $horaIni,
        string  $horaFin,
        ?string $asunto = null,
        ?string $sala   = null,
        ?string $notas  = null,
    ): string {
        $lista     = $this->listaParticipantes($nombresParticipantes);
        $asuntoTxt = $asunto ? "\n📋 Asunto: {$asunto}" : '';
        $salaTxt   = $sala   ? "\n📍 Sala: " . getSalaLabel($sala) : '';
        $notasTxt  = $notas  ? "\n📝 Notas: {$notas}"   : '';

        return "✏️ *Junta actualizada*\n"
            . "👤 Participantes: {$lista}\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $salaTxt
            . $asuntoTxt
            . $notasTxt;
    }

    /**
     * Mensaje para cada PARTICIPANTE cuando la junta es editada.
     * Vuelve a pedir confirmación porque los detalles cambiaron.
     */
    public function mensajeEdicionParaParticipante(
        string  $nombreOrganizador,
        string  $fecha,
        string  $horaIni,
        string  $horaFin,
        ?string $asunto = null,
        ?string $sala   = null,
        ?string $notas  = null,
    ): string {
        $asuntoTxt = $asunto ? "\n📋 Asunto: {$asunto}" : '';
        $salaTxt   = $sala   ? "\n📍 Sala: " . getSalaLabel($sala) : '';
        $notasTxt  = $notas  ? "\n📝 Notas: {$notas}"   : '';

        return "✏️ *{$nombreOrganizador}* actualizó una junta contigo.\n"
            . "📅 {$fecha}\n"
            . "🕐 de {$horaIni} a {$horaFin}"
            . $salaTxt
            . $asuntoTxt
            . $notasTxt
            . "\n\n✋ *Por favor confirma nuevamente tu asistencia* en la app.";
    }

    /**
     * Mensaje para el ORGANIZADOR al cancelar/eliminar la junta.
     */
    public function mensajeCancelacionParaOrganizador(
        array   $nombresParticipantes,
        string  $fecha,
        string  $horaIni,
        ?string $asunto = null,
    ): string {
        $lista     = $this->listaParticipantes($nombresParticipantes);
        $asuntoTxt = $asunto ? "\n📋 Asunto: {$asunto}" : '';

        return "❌ *Junta cancelada*\n"
            . "👤 Participantes: {$lista}\n"
            . "📅 {$fecha}\n"
            . "🕐 {$horaIni}"
            . $asuntoTxt;
    }

    /**
     * Mensaje para cada PARTICIPANTE cuando la junta es cancelada/eliminada.
     */
    public function mensajeCancelacionParaParticipante(
        string  $nombreOrganizador,
        string  $fecha,
        string  $horaIni,
        ?string $asunto = null,
    ): string {
        $asuntoTxt = $asunto ? "\n📋 Asunto: {$asunto}" : '';

        return "❌ *{$nombreOrganizador}* canceló la junta del día *{$fecha}* a las *{$horaIni}*."
            . $asuntoTxt;
    }

    /**
     * Mensaje cuando alguien cambia el estado de la junta.
     * $esMiPropio = true si quien recibe es el que hizo el cambio.
     */
    public function mensajeCambioEstado(
        string  $quienCambia,
        string  $contraparte,
        string  $estadoAnterior,
        string  $estadoNuevo,
        string  $fecha,
        string  $horaIni,
        string  $horaFin,
        bool    $esMiPropio,
    ): string {
        $transicion = "*{$estadoAnterior}* → *{$estadoNuevo}*";

        if ($esMiPropio) {
            return "✅ Cambiaste el estado de la junta con *{$contraparte}*\n"
                . "📅 {$fecha} · 🕐 de {$horaIni} a {$horaFin}\n\n"
                . "Estado: {$transicion}";
        }

        return "⚠️ *{$quienCambia}* cambió el estado de la junta contigo\n"
            . "📅 {$fecha} · 🕐 de {$horaIni} a {$horaFin}\n\n"
            . "Estado: {$transicion}";
    }

    // ─────────────────────────────────────────────
    // ENVÍO CON JOB (Controllers)
    // ─────────────────────────────────────────────

    /**
     * Envía mensajes a TODOS los involucrados de una junta:
     * - 1 mensaje al organizador con la lista completa de participantes
     * - 1 mensaje a cada participante pidiendo confirmación
     *
     * @param string      $telefonoOrganizador
     * @param string      $nombreOrganizador
     * @param array       $participantes  [ ['telefono' => '...', 'nombre' => '...'], ... ]
     * @param string      $fecha
     * @param string      $horaIni
     * @param string      $horaFin
     * @param string|null $asunto
     * @param string|null $sala
     * @param string|null $notas
     * @param string      $tipo           'creacion' | 'edicion'
     */
    public function notificarTodos(
        ?string $telefonoOrganizador,
        string  $nombreOrganizador,
        array   $participantes,
        string  $fecha,
        string  $horaIni,
        string  $horaFin,
        ?string $asunto = null,
        ?string $sala   = null,
        ?string $notas  = null,
        string  $tipo   = 'creacion',
    ): void {
        $nombresParticipantes = array_column($participantes, 'nombre');
        $queueIndex = 0;

        // ── Mensaje al organizador ──
        if ($telefonoOrganizador) {
            $msgOrg = $tipo === 'edicion'
                ? $this->mensajeEdicionParaOrganizador($nombresParticipantes, $fecha, $horaIni, $horaFin, $asunto, $sala, $notas)
                : $this->mensajeCreacionParaOrganizador($nombreOrganizador, $nombresParticipantes, $fecha, $horaIni, $horaFin, $asunto, $sala, $notas);

            $this->despacharJob($telefonoOrganizador, $msgOrg, $queueIndex++);
        }

        // ── Mensaje a cada participante ──
        foreach ($participantes as $p) {
            $telefono = $p['telefono'] ?? null;
            $nombre   = $p['nombre']   ?? 'Participante';

            if (!$telefono) continue;

            $msgPartic = $tipo === 'edicion'
                ? $this->mensajeEdicionParaParticipante($nombreOrganizador, $fecha, $horaIni, $horaFin, $asunto, $sala, $notas)
                : $this->mensajeInvitacionParaParticipante($nombreOrganizador, $nombre, $fecha, $horaIni, $horaFin, $asunto, $sala, $notas);

            $this->despacharJob($telefono, $msgPartic, $queueIndex++);
        }
    }

    /**
     * Notifica cancelación a todos los participantes de una junta.
     */
    public function notificarCancelacionTodos(
        ?string $telefonoOrganizador,
        string  $nombreOrganizador,
        array   $participantes,
        string  $fecha,
        string  $horaIni,
        ?string $asunto = null,
    ): void {
        $nombresParticipantes = array_column($participantes, 'nombre');
        $queueIndex = 0;

        if ($telefonoOrganizador) {
            $msg = $this->mensajeCancelacionParaOrganizador($nombresParticipantes, $fecha, $horaIni, $asunto);
            $this->despacharJob($telefonoOrganizador, $msg, $queueIndex++);
        }

        foreach ($participantes as $p) {
            $telefono = $p['telefono'] ?? null;
            if (!$telefono) continue;

            $msg = $this->mensajeCancelacionParaParticipante($nombreOrganizador, $fecha, $horaIni, $asunto);
            $this->despacharJob($telefono, $msg, $queueIndex++);
        }
    }

    // ─────────────────────────────────────────────
    // FORMATO DE FECHAS Y HORAS
    // ─────────────────────────────────────────────

    public function formatFecha(string $fecha): string
    {
        return Carbon::parse($fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    public function formatHora($hora): string
    {
        $c      = $hora instanceof Carbon ? $hora : Carbon::parse($hora);
        $sufijo = $c->format('A') === 'AM' ? 'am' : 'pm';
        return $c->format('g:i') . ' ' . $sufijo;
    }

    // ─────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────

    function getSalaLabel(string $sala): string
    {
        $labels = [
            'sala_tejido'    => 'Sala de juntas de tejido',
            'sala_junta'     => 'Sala de juntas (piso 2)',
            'oficina_sabu'   => 'Oficina de Sabu',
            'oficina_jaime'  => 'Oficina de Jaime',
            'remota'         => 'Remota (videollamada)',
        ];

        return $labels[$sala] ?? $sala;
    }

    private function listaParticipantes(array $nombres): string
    {
        return implode(', ', array_map(fn($n) => "*{$n}*", $nombres));
    }

    private function despacharJob(string $telefono, string $mensaje, int $delayMinutos = 0): void
    {
        EnviarMensajeWhatsappJob::dispatch($telefono, $mensaje)
            ->delay(now()->addMinutes($delayMinutos))
            ->onQueue('whatsapp');

        Log::info('📨 JUNTA WhatsApp encolado', [
            'telefono'      => $telefono,
            'delay_minutos' => $delayMinutos,
        ]);
    }



    // ── Sin constructor, no necesita inyección ──

    // ─────────────────────────────────────────────
    // TELÉFONO
    // ─────────────────────────────────────────────

    public function obtenerTelefonoDeIdentity(UserFirebirdIdentity $identity): ?string
    {
        $campos = ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR', 'TEL_PARTICULAR'];

        try {
            if ($identity->firebird_tb_clave !== null) {
                $empresa     = $identity->firebird_empresa ?? '04';
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($r) => trim((string) $r->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    foreach ($campos as $c) {
                        if (!empty($tbRow->$c)) return $tbRow->$c;
                    }
                }

                return null;
            }

            // CLIE, VEND, PROV
            [$table, $where, $param] = match (true) {
                $identity->firebird_clie_clave !== null => ['CLIE03', 'CLAVE',       $identity->firebird_clie_clave],
                $identity->firebird_vend_clave !== null => ['VEND03', 'CVE_VEND',    $identity->firebird_vend_clave],
                $identity->firebird_prov_clave !== null => ['PROV03', 'TRIM(CLAVE)', trim((string) $identity->firebird_prov_clave)],
                default                                 => [null, null, null],
            };

            if ($table) {
                $conn = DB::connection('firebird_produccion');
                $row  = $conn->selectOne(
                    'SELECT ' . implode(',', $campos) . " FROM {$table} WHERE {$where} = ?",
                    [$param]
                );

                if ($row) {
                    foreach ($campos as $c) {
                        if (!empty($row->$c)) return $row->$c;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_TELEFONO_IDENTITY_ERROR', [
                'identity_id' => $identity->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // NOTIFICACIÓN ASISTENCIA
    // ─────────────────────────────────────────────

    public function notificarAsistencia(
        UserFirebirdIdentity $organizador,
        UserFirebirdIdentity $participante,
        string $asistencia,
        string $fecha,
        string $horaIni,
        string $horaFin,
        ?string $asunto = null,
    ): void {
        $telefonoOrg    = $this->obtenerTelefonoDeIdentity($organizador);
        $telefonoPartic = $this->obtenerTelefonoDeIdentity($participante);
        $nombrePartic   = $participante->firebirdUser?->NOMBRE ?? 'Un participante';
        $nombreOrg      = $organizador->firebirdUser?->NOMBRE  ?? 'El organizador';

        $asuntoTxt    = $asunto ? "\n📋 Asunto: {$asunto}" : '';
        $emoji        = $asistencia === 'confirmada' ? '✅' : '❌';
        $accion       = $asistencia === 'confirmada' ? 'confirmó su asistencia'  : 'rechazó su asistencia';
        $accionPropia = $asistencia === 'confirmada' ? 'confirmaste tu asistencia' : 'rechazaste tu asistencia';

        $queueIndex = 0;

        if ($telefonoOrg) {
            $msg = "{$emoji} *{$nombrePartic}* {$accion} a la junta del *{$fecha}* de {$horaIni} a {$horaFin}.{$asuntoTxt}";
            $this->despacharJob($telefonoOrg, $msg, $queueIndex++);
        }

        if ($telefonoPartic) {
            $msg = "{$emoji} Has {$accionPropia} a la junta con *{$nombreOrg}* del *{$fecha}* de {$horaIni} a {$horaFin}.{$asuntoTxt}";
            $this->despacharJob($telefonoPartic, $msg, $queueIndex++);
        }
    }
}