<?php

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\UserFirebirdIdentity;
use App\Services\ExcludedFirebirdUsersService;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaManualService;
use App\Services\UserService;
use App\Jobs\EnviarMensajeWhatsappJob;
use App\Services\Agenda\CitaNotificacionService;
use App\Services\Agenda\JuntaNotificacionService;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AgendarJuntasController extends Controller
{
    private string $jwtSecret;
    private UserService $userService;
    private CitaNotificacionService $notif;
    private static int $whatsappQueueIndex = 0;
    protected $firebird;


    public function __construct(
        FirebirdConnectionService $firebirdConnection,
        FirebirdEmpresaManualService $firebird,
        UserService $userService,
        CitaNotificacionService $notif,
        JuntaNotificacionService $juntaNotif,
        private ExcludedFirebirdUsersService $excludedUsers,
    ) {
        $this->jwtSecret    = config('jwt.secret') ?? env('JWT_SECRET');
        $this->userService  = $userService;
        $this->notif        = $notif;
        $this->juntaNotif   = $juntaNotif;
        $this->firebird     = $firebird;
        $this->firebirdConn = $firebirdConnection;
    }

    /* ── Helper: identity del usuario autenticado ── */
    private function getIdentityFromToken(Request $request): ?UserFirebirdIdentity
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        $sub = (int) $decoded->sub;
        if (!$sub) return null;

        return UserFirebirdIdentity::where('firebird_user_clave', $sub)->first();
    }

    /* =============================================================
     | 📄 INDEX — juntas del usuario autenticado
     ============================================================= */
    public function index(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // Una junta aparece si eres el organizador (id_user) o participante (id_visitante)
        $juntas = Cita::with(['usuario', 'visitante'])
            ->where('cita_type_id', 2) // 2 = junta interna
            ->where(function ($q) use ($identity) {
                $q->where('id_user', $identity->id)
                    ->orWhere('id_visitante', $identity->id);
            })
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio', 'asc')
            ->get();

        return response()->json($juntas);
    }

    /* =============================================================
 | 💾 STORE — crear junta
 ============================================================= */
    public function store(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'fecha'           => 'required|date',
            'hora_inicio'     => 'required',
            'hora_fin'        => 'required|after:hora_inicio',
            'participantes'   => 'required|array|min:1',
            'participantes.*' => 'required|integer',
            'asunto'          => 'nullable|string|max:255',
            'sala'            => 'nullable|string|max:100',
            'estado'          => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'           => 'nullable|string',
        ], [
            'participantes.required' => 'Debes seleccionar al menos un participante.',
            'participantes.min'      => 'Debes seleccionar al menos un participante.',
            'hora_fin.after'         => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'         => 'La fecha es obligatoria.',
        ]);

        $idOrganizador = $identity->id;

        $fechaHoraInicio = new \DateTime("{$request->fecha}T{$request->hora_inicio}:00");
        if ($fechaHoraInicio <= new \DateTime()) {
            return response()->json([
                'message' => 'No puedes agendar una junta en una fecha u hora que ya pasó.',
                'errores' => [],
            ], 422);
        }

        $cruceOrganizador = Cita::where('id_user', $idOrganizador)
            ->where('fecha', $request->fecha)
            ->where('hora_inicio', '<', $request->hora_fin)
            ->where('hora_fin', '>', $request->hora_inicio)
            ->exists();

        if ($cruceOrganizador) {
            return response()->json([
                'message' => 'Ya tienes una cita o junta agendada en ese horario.',
                'errores' => [],
            ], 422);
        }

        $meData            = $this->userService->me($request);
        $nombreOrganizador = $meData['user']['TB']->NOMBRE
            ?? $meData['user']['CLIE']->NOMBRE
            ?? $meData['user']['VEND']->NOMBRE
            ?? $meData['user']['PROV']?->NOMBRE
            ?? $meData['user']['name']
            ?? 'Un colaborador';
        $telefonoOrganizador = $this->obtenerTelefonoUsuario($meData['user']);

        $juntasCreadas  = [];
        $participantesOk = []; // ← acumulamos los que sí se crearon
        $errores        = [];

        foreach ($request->participantes as $idParticipante) {
            $participanteIdentity = UserFirebirdIdentity::where('firebird_user_clave', $idParticipante)->first();

            if (!$participanteIdentity) {
                $errores[] = "Participante con id {$idParticipante} no encontrado.";
                continue;
            }

            if (is_null($participanteIdentity->firebird_tb_clave)) {
                $errores[] = "El participante ID {$idParticipante} no es un usuario interno.";
                continue;
            }

            $cruce = Cita::where(function ($q) use ($participanteIdentity) {
                $q->where('id_user', $participanteIdentity->id)
                    ->orWhere('id_visitante', $participanteIdentity->id);
            })
                ->where('fecha', $request->fecha)
                ->where('hora_inicio', '<', $request->hora_fin)
                ->where('hora_fin', '>', $request->hora_inicio)
                ->first();

            if ($cruce) {
                $nombrePartic = $participanteIdentity->firebirdUser->NOMBRE ?? "ID {$idParticipante}";
                $horaIniCruce = Carbon::parse($cruce->hora_inicio)->format('g:i a');
                $horaFinCruce = Carbon::parse($cruce->hora_fin)->format('g:i a');
                $errores[]    = "{$nombrePartic} ya tiene una cita de {$horaIniCruce} a {$horaFinCruce}.";
                continue;
            }

            $nombrePartic = $participanteIdentity->firebirdUser->NOMBRE ?? null;

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

            $juntasCreadas[] = $junta;

            // ← Acumulamos nombre + teléfono de cada participante creado con éxito
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

        if (empty($juntasCreadas)) {
            return response()->json([
                'message' => 'No se pudo crear ninguna junta.',
                'errores' => $errores,
            ], 422);
        }

        // ── Un solo bloque de WhatsApps para todos ──────────────────
        // Al organizador: "Agendaste junta con Fulano, Mengano..."
        // A cada participante: "Te invitaron... por favor confirma asistencia"
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

        return response()->json([
            'message' => count($juntasCreadas) . ' junta(s) registrada(s) con éxito.',
            'juntas'  => $juntasCreadas,
            'errores' => $errores,
        ], 201);
    }


    /* =============================================================
 | ✏️ UPDATE — editar junta
 ============================================================= */
    public function update(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $junta = Cita::where('cita_type_id', 2)
            ->where('id_user', $identity->id)
            ->find($id);

        if (!$junta) {
            return response()->json(['message' => 'Junta no encontrada'], 404);
        }

        $request->validate([
            'fecha'           => 'sometimes|required|date',
            'hora_inicio'     => 'sometimes|required',
            'hora_fin'        => 'sometimes|required|after:hora_inicio',
            'participantes'   => 'sometimes|array|min:1',
            'participantes.*' => 'integer',
            'asunto'          => 'nullable|string|max:255',
            'sala'            => 'nullable|string|max:100',
            'estado'          => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'           => 'nullable|string',
        ], [
            'hora_fin.after' => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required' => 'La fecha es obligatoria.',
        ]);

        $idOrganizador = $identity->id;
        $fecha         = $request->fecha       ?? $junta->fecha;
        $hora_inicio   = $request->hora_inicio ?? $junta->hora_inicio;
        $hora_fin      = $request->hora_fin    ?? $junta->hora_fin;
        $sala          = $request->sala        ?? $junta->sala;

        // ── Obtener TODOS los ids de esta junta (misma fecha/hora/organizador) ──
        $idsJuntaActual = Cita::where('cita_type_id', 2)
            ->where('id_user', $idOrganizador)
            ->where('fecha', $junta->fecha)
            ->where('hora_inicio', $junta->hora_inicio)
            ->where('hora_fin', $junta->hora_fin)
            ->pluck('id');

        $cruceOrganizador = Cita::where('id_user', $idOrganizador)
            ->where('fecha', $fecha)
            ->whereNotIn('id', $idsJuntaActual)
            ->where('hora_inicio', '<', $hora_fin)
            ->where('hora_fin', '>', $hora_inicio)
            ->exists();

        if ($cruceOrganizador) {
            return response()->json([
                'message' => 'Ya tienes una cita o junta agendada en ese horario.',
                'errores' => [],
            ], 422);
        }

        $meData            = $this->userService->me($request);
        $nombreOrganizador = $meData['user']['TB']->NOMBRE
            ?? $meData['user']['CLIE']->NOMBRE
            ?? $meData['user']['VEND']->NOMBRE
            ?? $meData['user']['PROV']?->NOMBRE
            ?? $meData['user']['name']
            ?? 'Un colaborador';
        $telefonoOrganizador = $this->obtenerTelefonoUsuario($meData['user']);

        if ($request->has('participantes')) {
            $juntasActualizadas = [];
            $participantesOk    = [];
            $errores            = [];

            // ── NUEVO: Eliminar filas de participantes que ya no están en la lista ──
            // Obtener las identities de los participantes que SÍ vienen en el request
            $identitiesNuevas = UserFirebirdIdentity::whereIn('firebird_user_clave', $request->participantes)
                ->pluck('id');

            // Eliminar filas de esta junta cuyos id_visitante NO están en los nuevos participantes
            Cita::whereIn('id', $idsJuntaActual)
                ->whereNotIn('id_visitante', $identitiesNuevas)
                ->where('id', '!=', $id) // no tocar la fila principal que vamos a actualizar
                ->delete();

            foreach ($request->participantes as $idParticipante) {
                $participanteIdentity = UserFirebirdIdentity::where('firebird_user_clave', $idParticipante)->first();

                if (!$participanteIdentity) {
                    $errores[] = "Participante con id {$idParticipante} no encontrado.";
                    continue;
                }

                if (is_null($participanteIdentity->firebird_tb_clave)) {
                    $errores[] = "El participante ID {$idParticipante} no es un usuario interno.";
                    continue;
                }

                $cruce = Cita::where(function ($q) use ($participanteIdentity) {
                    $q->where('id_user', $participanteIdentity->id)
                        ->orWhere('id_visitante', $participanteIdentity->id);
                })
                    ->where('fecha', $fecha)
                    ->whereNotIn('id', $idsJuntaActual)
                    ->where('hora_inicio', '<', $hora_fin)
                    ->where('hora_fin', '>', $hora_inicio)
                    ->first();

                if ($cruce) {
                    $nombrePartic = $participanteIdentity->firebirdUser->NOMBRE ?? "ID {$idParticipante}";
                    $horaIniCruce = Carbon::parse($cruce->hora_inicio)->format('g:i a');
                    $horaFinCruce = Carbon::parse($cruce->hora_fin)->format('g:i a');
                    $errores[]    = "{$nombrePartic} ya tiene una cita de {$horaIniCruce} a {$horaFinCruce}.";
                    continue;
                }

                $nombrePartic = $participanteIdentity->firebirdUser->NOMBRE ?? null;

                $junta->update([
                    'id_visitante'     => $participanteIdentity->id,
                    'nombre_visitante' => $nombrePartic,
                    'fecha'            => $fecha,
                    'hora_inicio'      => $hora_inicio,
                    'hora_fin'         => $hora_fin,
                    'motivo'           => $request->asunto ?? $junta->motivo,
                    'estado'           => $request->estado ?? $junta->estado,
                    'notas'            => $request->notas  ?? $junta->notas,
                    'sala'             => $sala,
                ]);

                $juntasActualizadas[] = $junta->fresh();

                $participantesOk[] = [
                    'nombre'   => $nombrePartic ?? "ID {$idParticipante}",
                    'telefono' => $this->obtenerTelefonoDeIdentity($participanteIdentity),
                ];
            }

            if (empty($juntasActualizadas)) {
                return response()->json([
                    'message' => 'No se pudo actualizar ninguna junta.',
                    'errores' => $errores,
                ], 422);
            }

            try {
                $this->juntaNotif->notificarTodos(
                    telefonoOrganizador: $telefonoOrganizador,
                    nombreOrganizador: $nombreOrganizador,
                    participantes: $participantesOk,
                    fecha: $this->juntaNotif->formatFecha($fecha),
                    horaIni: $this->juntaNotif->formatHora($hora_inicio),
                    horaFin: $this->juntaNotif->formatHora($hora_fin),
                    asunto: $request->asunto ?? $junta->motivo,
                    sala: $sala,
                    notas: $request->notas  ?? $junta->notas,
                    tipo: 'edicion',
                );
            } catch (\Throwable $e) {
                Log::error('❌ JUNTA_UPDATE_WHATSAPP', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'message' => count($juntasActualizadas) . ' junta(s) actualizada(s) con éxito.',
                'juntas'  => $juntasActualizadas,
                'errores' => $errores,
            ]);
        }

        // ── SIN cambio de participantes ──
        $participanteActual  = UserFirebirdIdentity::find($junta->id_visitante);
        $nombreParticActual  = $junta->nombre_visitante ?? 'el participante';
        $asunto              = $request->asunto ?? $junta->motivo;

        $junta->update([
            'fecha'       => $fecha,
            'hora_inicio' => $hora_inicio,
            'hora_fin'    => $hora_fin,
            'motivo'      => $asunto,
            'estado'      => $request->estado ?? $junta->estado,
            'notas'       => $request->notas  ?? $junta->notas,
            'sala'        => $sala,
        ]);

        try {
            $participantesOk = [[
                'nombre'   => $nombreParticActual,
                'telefono' => $participanteActual
                    ? $this->obtenerTelefonoDeIdentity($participanteActual)
                    : null,
            ]];

            $this->juntaNotif->notificarTodos(
                telefonoOrganizador: $telefonoOrganizador,
                nombreOrganizador: $nombreOrganizador,
                participantes: $participantesOk,
                fecha: $this->juntaNotif->formatFecha($fecha),
                horaIni: $this->juntaNotif->formatHora($hora_inicio),
                horaFin: $this->juntaNotif->formatHora($hora_fin),
                asunto: $asunto,
                sala: $sala,
                notas: $request->notas ?? $junta->notas,
                tipo: 'edicion',
            );
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_UPDATE_SIMPLE_WHATSAPP', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Junta actualizada con éxito.',
            'junta'   => $junta->fresh(),
        ]);
    }


    /* =============================================================
 | 🗑️ DESTROY — eliminar junta
 ============================================================= */
    public function destroy(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $junta = Cita::where('cita_type_id', 2)
            ->where('id_user', $identity->id)
            ->find($id);

        if (!$junta) {
            return response()->json(['message' => 'Junta no encontrada'], 404);
        }

        $meData   = $this->userService->me($request);
        $nombreOrg = $meData['user']['TB']->NOMBRE
            ?? $meData['user']['CLIE']->NOMBRE
            ?? $meData['user']['VEND']->NOMBRE
            ?? $meData['user']['name']
            ?? 'Un colaborador';
        $telefonoOrganizador = $this->obtenerTelefonoUsuario($meData['user']);

        // Recolectar participante(s) antes de borrar
        $participantesOk = [];
        $participante    = UserFirebirdIdentity::find($junta->id_visitante);
        if ($participante) {
            $participantesOk[] = [
                'nombre'   => $junta->nombre_visitante ?? $participante->firebirdUser->NOMBRE ?? 'Participante',
                'telefono' => $this->obtenerTelefonoDeIdentity($participante),
            ];
        }

        // Notificar antes de borrar
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

        return response()->json(['message' => 'Junta eliminada correctamente'], 200);
    }

    /* =============================================================
     | ✅ UPDATE ESTADO
     ============================================================= */
    public function updateEstado(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'estado' => 'required|in:pendiente,confirmada,cancelada',
        ]);

        $junta = Cita::where('cita_type_id', 2)
            ->where(function ($q) use ($identity) {
                $q->where('id_user', $identity->id)
                    ->orWhere('id_visitante', $identity->id);
            })
            ->find($id);

        if (!$junta) {
            return response()->json(['message' => 'Junta no encontrada'], 404);
        }

        if ($junta->estado === $request->estado) {
            return response()->json(['message' => 'El estado ya era el mismo.', 'junta' => $junta]);
        }

        $estadoAnterior = $junta->estado;
        $junta->update(['estado' => $request->estado]);

        $organizadorIdentity   = UserFirebirdIdentity::find($junta->id_user);
        $participanteIdentity  = UserFirebirdIdentity::find($junta->id_visitante);
        $nombreOrg             = $organizadorIdentity?->firebirdUser->NOMBRE  ?? 'Organizador';
        $nombrePartic          = $participanteIdentity?->firebirdUser->NOMBRE ?? 'Participante';
        $telefonoOrg           = $organizadorIdentity  ? $this->obtenerTelefonoDeIdentity($organizadorIdentity)  : null;
        $telefonoPartic        = $participanteIdentity ? $this->obtenerTelefonoDeIdentity($participanteIdentity) : null;

        $fecha   = Carbon::parse($junta->fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $horaIni = Carbon::parse($junta->hora_inicio)->format('g:i a');
        $horaFin = Carbon::parse($junta->hora_fin)->format('g:i a');

        $yoSoyOrganizador = $identity->id === $junta->id_user;
        $quienCambia      = $yoSoyOrganizador ? $nombreOrg : $nombrePartic;
        $miContraparte    = $yoSoyOrganizador ? $nombrePartic : $nombreOrg;
        $mensajeEstado    = "Estado: *{$estadoAnterior}* → *{$request->estado}*";

        $msgPropio    = "✅ Cambiaste el estado de la junta con *{$miContraparte}* del día *{$fecha}* de *{$horaIni}* a *{$horaFin}*.\n\n{$mensajeEstado}";
        $msgTercero   = "⚠️ *{$quienCambia}* cambió el estado de la junta contigo del día *{$fecha}* de *{$horaIni}* a *{$horaFin}*.\n\n{$mensajeEstado}";

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

        return response()->json([
            'message' => 'Estado actualizado.',
            'junta'   => $junta->fresh(),
        ]);
    }

    /* =============================================================
     | 🔧 HELPERS PRIVADOS
     ============================================================= */
    private function enviarWhatsapp(string $telefono, string $mensaje): void
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

    private function obtenerTelefonoUsuario(array $userData): ?string
    {
        $tipo = $userData['tipo_usuario'] ?? null;

        $map = [
            'empleado'  => ['TB',   ['TELEFONO', 'TEL', 'TEL_CELULAR', 'CELULAR', 'TEL_PARTICULAR']],
            'cliente'   => ['CLIE', ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR']],
            'vendedor'  => ['VEND', ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR']],
        ];

        if (!isset($map[$tipo])) return null;

        [$key, $campos] = $map[$tipo];
        $registro = $userData[$key] ?? null;
        if (!$registro) return null;

        foreach ($campos as $campo) {
            if (!empty($registro->$campo)) return $registro->$campo;
        }

        return null;
    }

    private function obtenerTelefonoDeIdentity(UserFirebirdIdentity $identity): ?string
    {
        $campos = ['TELEFONO', 'TEL', 'CELULAR', 'TEL_CELULAR', 'TEL_PARTICULAR'];

        try {
            // Empleado (TB)
            if ($identity->firebird_tb_clave !== null) {
                $empresa     = $identity->firebird_empresa ?? '04';
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
                $tbRow       = $firebirdNoi->getOperationalTable('TB')
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
            $table = null;
            $where = null;
            $param = null;

            if ($identity->firebird_clie_clave !== null) {
                $table = 'CLIE03';
                $where = 'CLAVE';
                $param = $identity->firebird_clie_clave;
            } elseif ($identity->firebird_vend_clave !== null) {
                $table = 'VEND03';
                $where = 'CVE_VEND';
                $param = $identity->firebird_vend_clave;
            } elseif ($identity->firebird_prov_clave !== null) {
                $table = 'PROV03';
                $where = 'TRIM(CLAVE)';
                $param = trim((string) $identity->firebird_prov_clave);
            }

            if ($table) {
                $row = $conn->selectOne("SELECT " . implode(',', $campos) . " FROM {$table} WHERE {$where} = ?", [$param]);
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



    public function updateAsistencia(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        $request->validate([
            'asistencia' => 'required|in:pendiente,confirmada,rechazada',
        ]);

        // Solo el PARTICIPANTE (id_visitante) puede confirmar su asistencia
        $junta = Cita::where('cita_type_id', 2)
            ->where('id_visitante', $identity->id)
            ->find($id);

        if (!$junta) {
            return response()->json(['message' => 'Junta no encontrada o no eres participante'], 404);
        }

        $junta->update(['asistencia' => $request->asistencia]);

        // Notificar al organizador
        try {
            $organizador = UserFirebirdIdentity::find($junta->id_user);
            $telefonoOrg = $organizador ? $this->obtenerTelefonoDeIdentity($organizador) : null;
            $nombrePartic = $identity->firebirdUser->NOMBRE ?? 'Un participante';

            $emoji = $request->asistencia === 'confirmada' ? '✅' : '❌';
            $texto = $request->asistencia === 'confirmada' ? 'confirmó su asistencia' : 'rechazó su asistencia';

            if ($telefonoOrg) {
                $this->enviarWhatsapp(
                    $telefonoOrg,
                    "{$emoji} *{$nombrePartic}* {$texto} a la junta del *"
                        . $this->juntaNotif->formatFecha($junta->fecha)
                        . "* de "
                        . $this->juntaNotif->formatHora($junta->hora_inicio)
                        . " a "
                        . $this->juntaNotif->formatHora($junta->hora_fin)
                );
            }
        } catch (\Throwable $e) {
            Log::error('❌ JUNTA_ASISTENCIA_WHATSAPP', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'message'    => 'Asistencia actualizada.',
            'junta'      => $junta->fresh(),
        ]);
    }
}