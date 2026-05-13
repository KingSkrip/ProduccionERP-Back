<?php

namespace App\Services\Agenda;

use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use App\Services\UserService;
use App\Jobs\EnviarMensajeWhatsappJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JuntaService
{
    private static int $whatsappQueueIndex = 0;

    public function __construct(
        private FirebirdEmpresaManualService $firebird,
        private UserService $userService,
        private JuntaNotificacionService $juntaNotif,
    ) {}

    /* ══════════════════════════════════════════════
     |  QUEUE INDEX
     ══════════════════════════════════════════════ */

    public function resetQueueIndex(): void
    {
        self::$whatsappQueueIndex = 0;
    }

    /* ══════════════════════════════════════════════
     |  CONSULTAS
     ══════════════════════════════════════════════ */

    public function listarJuntasDeIdentity(UserFirebirdIdentity $identity): \Illuminate\Support\Collection
    {
        return Cita::with(['usuario', 'visitante'])
            ->where('cita_type_id', 2)
            ->where(function ($q) use ($identity) {
                $q->where('id_user', $identity->id)
                    ->orWhere('id_visitante', $identity->id);
            })
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio', 'asc')
            ->get();
    }

    /* ══════════════════════════════════════════════
     |  VALIDACIONES
     ══════════════════════════════════════════════ */

    public function hayCruceEnAgendaDeOrganizador(
        int $idUser,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        array $excludeIds = []
    ): bool {
        $query = Cita::where('id_user', $idUser)
            ->where('fecha', $fecha)
            ->where('hora_inicio', '<', $horaFin)
            ->where('hora_fin', '>', $horaInicio);

        if (!empty($excludeIds)) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query->exists();
    }

    /**
     * Devuelve los IDs de todas las filas de una junta (mismo organizador/fecha/hora).
     */
    public function obtenerIdsDelGrupo(int $idOrganizador, Cita $junta): \Illuminate\Support\Collection
    {
        return Cita::where('cita_type_id', 2)
            ->where('id_user', $idOrganizador)
            ->where('fecha', $junta->fecha)
            ->where('hora_inicio', $junta->hora_inicio)
            ->where('hora_fin', $junta->hora_fin)
            ->pluck('id');
    }

    /* ══════════════════════════════════════════════
     |  CREAR
     ══════════════════════════════════════════════ */

    /**
     * @return array{juntasCreadas: Cita[], errores: string[]}
     */
    public function crearJuntas(Request $request, UserFirebirdIdentity $identity): array
    {
        $idOrganizador = $identity->id;
        $juntasCreadas  = [];
        $participantesOk = [];
        $errores         = [];
        $meData              = $this->userService->me($request);
        $nombreOrganizador   = $this->resolverNombreDeUserData($meData['user']);
        $telefonoOrganizador = $this->obtenerTelefonoUsuario($meData['user']);

        foreach ($request->participantes as $idParticipante) {
            $participanteIdentity = UserFirebirdIdentity::where('firebird_user_clave', $idParticipante)->first();

            if (!$participanteIdentity) {
                $errores[] = "Participante con id {$idParticipante} no encontrado.";
                continue;
            }

            // $cruce = Cita::where(function ($q) use ($participanteIdentity) {
            //     $q->where('id_user', $participanteIdentity->id)
            //         ->orWhere('id_visitante', $participanteIdentity->id);
            // })
            //     ->where('fecha', $request->fecha)
            //     ->where('hora_inicio', '<', $request->hora_fin)
            //     ->where('hora_fin', '>', $request->hora_inicio)
            //     ->first();
            // if ($cruce) {
            //     $errores[] = $this->mensajeCruce($participanteIdentity, $cruce);
            //     continue;
            // }
            // $nombrePartic = $participanteIdentity->firebirdUser->NOMBRE ?? null;
            
            $nombrePartic = $participanteIdentity->firebirdUser?->NOMBRE ?? "Participante {$idParticipante}";
            $junta = Cita::create([
                'cita_type_id'     => 2,
                'id_user'          => $idOrganizador,
                'id_visitante'     => $participanteIdentity->id,
                'nombre_visitante' => $nombrePartic,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->asunto,
                'estado'           => $request->estado ?? 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => false,
                'sala'             => $request->sala,
                'created_at'       => now(),
            ]);

            $juntasCreadas[]   = $junta;
            $participantesOk[] = [
                'nombre'   => $nombrePartic ?? "ID {$idParticipante}",
                'telefono' => $this->obtenerTelefonoDeIdentity($participanteIdentity),
            ];

            Log::info('✅ JUNTA_CREADA', [
                'junta_id'     => $junta->id,
                'organizador'  => $idOrganizador,
                'participante' => $idParticipante,
            ]);
        }

        if (!empty($juntasCreadas)) {
            try {
                $this->juntaNotif->notificarTodos(
                    telefonoOrganizador: $telefonoOrganizador,
                    nombreOrganizador: $nombreOrganizador,
                    participantes: $participantesOk,
                    fecha: $this->juntaNotif->formatFecha($request->fecha),
                    horaIni: $this->juntaNotif->formatHora($request->hora_inicio),
                    horaFin: $this->juntaNotif->formatHora($request->hora_fin),
                    asunto: $request->asunto,
                    sala: $request->sala,
                    notas: $request->notas,
                    tipo: 'creacion',
                );
            } catch (\Throwable $e) {
                Log::error('❌ JUNTA_STORE_WHATSAPP', ['error' => $e->getMessage()]);
            }
        }

        return compact('juntasCreadas', 'errores');
    }

    /* ══════════════════════════════════════════════
     |  ACTUALIZAR
     ══════════════════════════════════════════════ */

    /**
     * @return array{juntasActualizadas: Cita[], errores: string[]}|Cita
     *         Array si vinieron participantes nuevos, Cita fresca si fue update simple.
     */
    public function actualizarJunta(Request $request, Cita $junta, UserFirebirdIdentity $identity): array|Cita
    {
        $idOrganizador = $identity->id;
        $fecha         = $request->fecha       ?? $junta->fecha;
        $horaInicio    = $request->hora_inicio ?? $junta->hora_inicio;
        $horaFin       = $request->hora_fin    ?? $junta->hora_fin;
        $sala          = $request->sala        ?? $junta->sala;
        $idsGrupo      = $this->obtenerIdsDelGrupo($idOrganizador, $junta);

        $meData              = $this->userService->me($request);
        $nombreOrganizador   = $this->resolverNombreDeUserData($meData['user']);
        $telefonoOrganizador = $this->obtenerTelefonoUsuario($meData['user']);

        // ── Con cambio de participantes ───────────────────────────────────
        if ($request->has('participantes')) {
            $juntasActualizadas = [];
            $participantesOk    = [];
            $errores            = [];

            // Eliminar participantes que ya no están en la lista
            $identitiesNuevas = UserFirebirdIdentity::whereIn('firebird_user_clave', $request->participantes)
                ->pluck('id');

            Cita::whereIn('id', $idsGrupo)
                ->whereNotIn('id_visitante', $identitiesNuevas)
                ->where('id', '!=', $junta->id)
                ->delete();

            foreach ($request->participantes as $idParticipante) {
                $participanteIdentity = UserFirebirdIdentity::where('firebird_user_clave', $idParticipante)->first();

                if (!$participanteIdentity) {
                    $errores[] = "Participante con id {$idParticipante} no encontrado.";
                    continue;
                }

                // $cruce = Cita::where(function ($q) use ($participanteIdentity) {
                //     $q->where('id_user', $participanteIdentity->id)
                //         ->orWhere('id_visitante', $participanteIdentity->id);
                // })
                //     ->where('fecha', $fecha)
                //     ->whereNotIn('id', $idsGrupo)
                //     ->where('hora_inicio', '<', $horaFin)
                //     ->where('hora_fin', '>', $horaInicio)
                //     ->first();
                // if ($cruce) {
                //     $errores[] = $this->mensajeCruce($participanteIdentity, $cruce);
                //     continue;
                // }
                // $nombrePartic = $participanteIdentity->firebirdUser->NOMBRE ?? null;
                
                $nombrePartic = $participanteIdentity->firebirdUser?->NOMBRE ?? "Participante {$idParticipante}";
                $junta->update([
                    'id_visitante'     => $participanteIdentity->id,
                    'nombre_visitante' => $nombrePartic,
                    'fecha'            => $fecha,
                    'hora_inicio'      => $horaInicio,
                    'hora_fin'         => $horaFin,
                    'motivo'           => $request->asunto ?? $junta->motivo,
                    'estado'           => $request->estado ?? $junta->estado,
                    'notas'            => $request->notas  ?? $junta->notas,
                    'sala'             => $sala,
                ]);

                $juntasActualizadas[] = $junta->fresh();
                $participantesOk[]    = [
                    'nombre'   => $nombrePartic ?? "ID {$idParticipante}",
                    'telefono' => $this->obtenerTelefonoDeIdentity($participanteIdentity),
                ];
            }

            if (!empty($juntasActualizadas)) {
                try {
                    $this->juntaNotif->notificarTodos(
                        telefonoOrganizador: $telefonoOrganizador,
                        nombreOrganizador: $nombreOrganizador,
                        participantes: $participantesOk,
                        fecha: $this->juntaNotif->formatFecha($fecha),
                        horaIni: $this->juntaNotif->formatHora($horaInicio),
                        horaFin: $this->juntaNotif->formatHora($horaFin),
                        asunto: $request->asunto ?? $junta->motivo,
                        sala: $sala,
                        notas: $request->notas  ?? $junta->notas,
                        tipo: 'edicion',
                    );
                } catch (\Throwable $e) {
                    Log::error('❌ JUNTA_UPDATE_WHATSAPP', ['error' => $e->getMessage()]);
                }
            }

            return compact('juntasActualizadas', 'errores');
        }

        // ── Update simple (sin cambio de participantes) ───────────────────
        $participanteActual = UserFirebirdIdentity::find($junta->id_visitante);
        $nombreParticActual = $junta->nombre_visitante ?? 'el participante';
        $asunto             = $request->asunto ?? $junta->motivo;

        $junta->update([
            'fecha'       => $fecha,
            'hora_inicio' => $horaInicio,
            'hora_fin'    => $horaFin,
            'motivo'      => $asunto,
            'estado'      => $request->estado ?? $junta->estado,
            'notas'       => $request->notas  ?? $junta->notas,
            'sala'        => $sala,
        ]);

        try {
            $this->juntaNotif->notificarTodos(
                telefonoOrganizador: $telefonoOrganizador,
                nombreOrganizador: $nombreOrganizador,
                participantes: [[
                    'nombre'   => $nombreParticActual,
                    'telefono' => $participanteActual
                        ? $this->obtenerTelefonoDeIdentity($participanteActual)
                        : null,
                ]],
                fecha: $this->juntaNotif->formatFecha($fecha),
                horaIni: $this->juntaNotif->formatHora($horaInicio),
                horaFin: $this->juntaNotif->formatHora($horaFin),
                asunto: $asunto,
                sala: $sala,
                notas: $request->notas ?? $junta->notas,
                tipo: 'edicion',
            );
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_UPDATE_SIMPLE_WHATSAPP', ['error' => $e->getMessage()]);
        }

        return $junta->fresh();
    }

    /* ══════════════════════════════════════════════
     |  ELIMINAR
     ══════════════════════════════════════════════ */

    public function eliminarJunta(Request $request, Cita $junta, UserFirebirdIdentity $identity): void
    {
        $meData    = $this->userService->me($request);
        $nombreOrg = $this->resolverNombreDeUserData($meData['user']);
        $telefonoOrganizador = $this->obtenerTelefonoUsuario($meData['user']);

        $participantesOk = [];
        $participante    = UserFirebirdIdentity::find($junta->id_visitante);

        if ($participante) {
            $participantesOk[] = [
                'nombre'   => $junta->nombre_visitante ?? $participante->firebirdUser->NOMBRE ?? 'Participante',
                'telefono' => $this->obtenerTelefonoDeIdentity($participante),
            ];
        }

        try {
            $this->juntaNotif->notificarCancelacionTodos(
                telefonoOrganizador: $telefonoOrganizador,
                nombreOrganizador: $nombreOrg,
                participantes: $participantesOk,
                fecha: $this->juntaNotif->formatFecha($junta->fecha),
                horaIni: $this->juntaNotif->formatHora($junta->hora_inicio),
                asunto: $junta->motivo,
            );
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_DESTROY_WHATSAPP', ['error' => $e->getMessage()]);
        }

        $junta->delete();
    }

    /* ══════════════════════════════════════════════
     |  CAMBIAR ESTADO
     ══════════════════════════════════════════════ */

    /**
     * @return array{sin_cambio: bool, junta: Cita}
     */
    public function actualizarEstado(Request $request, Cita $junta, UserFirebirdIdentity $identity): array
    {
        if ($junta->estado === $request->estado) {
            return ['sin_cambio' => true, 'junta' => $junta];
        }

        $estadoAnterior = $junta->estado;
        $junta->update(['estado' => $request->estado]);

        $organizadorIdentity  = UserFirebirdIdentity::find($junta->id_user);
        $participanteIdentity = UserFirebirdIdentity::find($junta->id_visitante);
        $nombreOrg            = $organizadorIdentity?->firebirdUser->NOMBRE  ?? 'Organizador';
        $nombrePartic         = $participanteIdentity?->firebirdUser->NOMBRE ?? 'Participante';
        $telefonoOrg          = $organizadorIdentity  ? $this->obtenerTelefonoDeIdentity($organizadorIdentity)  : null;
        $telefonoPartic       = $participanteIdentity ? $this->obtenerTelefonoDeIdentity($participanteIdentity) : null;

        $fecha   = Carbon::parse($junta->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $horaIni = Carbon::parse($junta->hora_inicio)->format('g:i a');
        $horaFin = Carbon::parse($junta->hora_fin)->format('g:i a');

        $yoSoyOrganizador = $identity->id === $junta->id_user;
        $quienCambia      = $yoSoyOrganizador ? $nombreOrg    : $nombrePartic;
        $miContraparte    = $yoSoyOrganizador ? $nombrePartic : $nombreOrg;
        $mensajeEstado    = "Estado: *{$estadoAnterior}* → *{$request->estado}*";

        $msgPropio  = "✅ Cambiaste el estado de la junta con *{$miContraparte}* del día *{$fecha}* de *{$horaIni}* a *{$horaFin}*.\n\n{$mensajeEstado}";
        $msgTercero = "⚠️ *{$quienCambia}* cambió el estado de la junta contigo del día *{$fecha}* de *{$horaIni}* a *{$horaFin}*.\n\n{$mensajeEstado}";

        try {
            if ($telefonoOrg) {
                $this->enviarWhatsapp($telefonoOrg, $yoSoyOrganizador ? $msgPropio : $msgTercero);
            }
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_ESTADO_WHATSAPP_ORG', ['error' => $e->getMessage()]);
        }

        try {
            if ($telefonoPartic) {
                $this->enviarWhatsapp($telefonoPartic, !$yoSoyOrganizador ? $msgPropio : $msgTercero);
            }
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_ESTADO_WHATSAPP_PARTIC', ['error' => $e->getMessage()]);
        }

        return ['sin_cambio' => false, 'junta' => $junta->fresh()];
    }

    /* ══════════════════════════════════════════════
     |  ASISTENCIA
     ══════════════════════════════════════════════ */

    public function actualizarAsistencia(Request $request, Cita $junta, UserFirebirdIdentity $identity): array
    {
        $junta->update(['asistencia' => $request->asistencia]);

        try {
            $organizador    = UserFirebirdIdentity::find($junta->id_user);
            $telefonoOrg    = $organizador ? $this->obtenerTelefonoDeIdentity($organizador) : null;
            $telefonoPartic = $this->obtenerTelefonoDeIdentity($identity);
            $nombrePartic   = $identity->firebirdUser?->NOMBRE ?? 'Un participante';
            $nombreOrg      = $organizador?->firebirdUser?->NOMBRE ?? 'El organizador';

            $fecha   = $this->juntaNotif->formatFecha($junta->fecha);
            $horaIni = $this->juntaNotif->formatHora($junta->hora_inicio);
            $horaFin = $this->juntaNotif->formatHora($junta->hora_fin);
            $asunto  = $junta->motivo ? "\n📋 Asunto: {$junta->motivo}" : '';

            $emoji  = $request->asistencia === 'confirmada' ? '✅' : '❌';
            $accion = $request->asistencia === 'confirmada' ? 'confirmó su asistencia' : 'rechazó su asistencia';
            $accionPropia = $request->asistencia === 'confirmada' ? 'confirmaste tu asistencia' : 'rechazaste tu asistencia';

            // ── Notificar al ORGANIZADOR ──
            if ($telefonoOrg) {
                $msgOrg = "{$emoji} *{$nombrePartic}* {$accion} a la junta del *{$fecha}* de {$horaIni} a {$horaFin}.{$asunto}";
                $this->enviarWhatsapp($telefonoOrg, $msgOrg);
            }

            // ── Confirmar al PARTICIPANTE su propia acción ──
            if ($telefonoPartic) {
                $msgPartic = "{$emoji} Has {$accionPropia} a la junta con *{$nombreOrg}* del *{$fecha}* de {$horaIni} a {$horaFin}.{$asunto}";
                $this->enviarWhatsapp($telefonoPartic, $msgPartic);
            }
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_ASISTENCIA_WHATSAPP', ['error' => $e->getMessage()]);
        }

        return ['junta' => $junta->fresh()];
    }

    /* ══════════════════════════════════════════════
     |  WHATSAPP
     ══════════════════════════════════════════════ */

    public function enviarWhatsapp(string $telefono, string $mensaje): void
    {
        EnviarMensajeWhatsappJob::dispatch($telefono, $mensaje)
            ->delay(now()->addMinutes(self::$whatsappQueueIndex))
            ->onQueue('whatsapp');

        Log::info('📨 JUNTA WhatsApp encolado', [
            'telefono'    => $telefono,
            'queue_index' => self::$whatsappQueueIndex,
        ]);

        self::$whatsappQueueIndex++;
    }

    /* ══════════════════════════════════════════════
     |  HELPERS PRIVADOS
     ══════════════════════════════════════════════ */

    private function mensajeCruce(UserFirebirdIdentity $identity, Cita $cruce): string
    {
        $nombre  = $identity->firebirdUser->NOMBRE ?? "ID {$identity->id}";
        $horaIni = Carbon::parse($cruce->hora_inicio)->format('g:i a');
        $horaFin = Carbon::parse($cruce->hora_fin)->format('g:i a');

        return "{$nombre} ya tiene una cita de {$horaIni} a {$horaFin}.";
    }

    private function resolverNombreDeUserData(array $userData): string
    {
        return $userData['TB']->NOMBRE
            ?? $userData['CLIE']->NOMBRE
            ?? $userData['VEND']->NOMBRE
            ?? $userData['PROV']?->NOMBRE
            ?? $userData['name']
            ?? 'Un colaborador';
    }

    public function obtenerTelefonoUsuario(array $userData): ?string
    {
        $map = [
            'empleado' => ['TB',   ['TELEFONO', 'TEL', 'TEL_CELULAR', 'CELULAR', 'TEL_PARTICULAR']],
            'cliente'  => ['CLIE', ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR']],
            'vendedor' => ['VEND', ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR']],
        ];

        $tipo = $userData['tipo_usuario'] ?? null;
        if (!isset($map[$tipo])) return null;

        [$key, $campos] = $map[$tipo];
        $registro = $userData[$key] ?? null;
        if (!$registro) return null;

        foreach ($campos as $campo) {
            if (!empty($registro->$campo)) return $registro->$campo;
        }

        return null;
    }

    public function obtenerTelefonoDeIdentity(UserFirebirdIdentity $identity): ?string
    {
        $campos = ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR', 'TEL_PARTICULAR'];

        try {
            // 🏢 Empleado (TB / NOI)
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

            $conn  = $this->firebird->getProductionConnection();
            [$table, $where, $param] = match (true) {
                $identity->firebird_clie_clave !== null => ['CLIE03', 'CLAVE',      $identity->firebird_clie_clave],
                $identity->firebird_vend_clave !== null => ['VEND03', 'CVE_VEND',   $identity->firebird_vend_clave],
                $identity->firebird_prov_clave !== null => ['PROV03', 'TRIM(CLAVE)', trim((string) $identity->firebird_prov_clave)],
                default                                 => [null, null, null],
            };

            if ($table) {
                $row = $conn->selectOne(
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
}