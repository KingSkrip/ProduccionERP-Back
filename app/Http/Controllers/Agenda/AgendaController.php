<?php

namespace App\Http\Controllers\Agenda;

use App\Http\Controllers\Controller;
use App\Models\Cita;
use App\Models\ModelHasRole;
use App\Models\UserFirebirdIdentity;
use App\Services\Agenda\JuntaService;
use App\Services\Agenda\VisitaService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgendaController extends Controller
{
    private string $jwtSecret;

    public function __construct(
        private VisitaService $VisitaService,
        private JuntaService $juntaService,
    ) {
        $this->jwtSecret = config('jwt.secret') ?? env('JWT_SECRET');
    }

    /* ══════════════════════════════════════════════
     |  HELPERS
     ══════════════════════════════════════════════ */

    private function getIdentityFromToken(Request $request): ?UserFirebirdIdentity
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        $sub     = (int) $decoded->sub;
        if (!$sub) return null;

        return UserFirebirdIdentity::where('firebird_user_clave', $sub)->first();
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'No autenticado'], 401);
    }

    /* ══════════════════════════════════════════════
     |  ALL USERS
     ══════════════════════════════════════════════ */

    public function index(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        return response()->json(
            $this->VisitaService->listarCitasDeIdentity($identity)
        );
    }

    public function store(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $request->validate([
            'fecha'                    => 'required|date',
            'hora_inicio'              => 'required',
            'hora_fin'                 => 'required|after:hora_inicio',
            'visitantes'               => 'nullable|array',
            'visitantes.*'             => 'integer',
            'nombre_visitante_externo' => 'nullable|string|max:255',
            'motivo'                   => 'nullable|string|max:255',
            'estado'                   => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'                    => 'nullable|string',
            'con_vehiculo'             => 'nullable|boolean',
        ], [
            'hora_fin.after' => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required' => 'La fecha es obligatoria.',
        ]);

        $tieneRegistrados = !empty($request->visitantes);
        $tieneExterno     = strlen(trim((string) ($request->nombre_visitante_externo ?? ''))) > 0;

        if (!$tieneRegistrados && !$tieneExterno) {
            return response()->json([
                'message' => 'Debes seleccionar un visitante o escribir un nombre externo.',
                'errores' => [],
            ], 422);
        }

        if ($this->VisitaService->hayCruceEnAgendaDeAnfitrion($identity->id, $request->fecha, $request->hora_inicio, $request->hora_fin)) {
            return response()->json([
                'message' => 'Ya tienes una cita agendada en ese horario.',
                'errores' => [],
            ], 422);
        }

        $this->VisitaService->resetQueueIndex();
        ['citasCreadas' => $citasCreadas, 'errores' => $errores] = $this->VisitaService->crearCitasAnfitrion($request, $identity);

        if (empty($citasCreadas)) {
            return response()->json(['message' => 'No se pudo crear ninguna cita.', 'errores' => $errores], 422);
        }

        return response()->json([
            'message' => count($citasCreadas) . ' cita(s) registrada(s) con éxito.',
            'citas'   => $citasCreadas,
            'errores' => $errores,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $cita = Cita::with(['usuario', 'visitante'])
            ->where('id_user', $identity->id)
            ->find($id);

        if (!$cita) return response()->json(['message' => 'Cita no encontrada'], 404);

        return response()->json([
            'id'               => $cita->id,
            'fecha'            => $cita->fecha,
            'hora_inicio'      => $cita->hora_inicio,
            'hora_fin'         => $cita->hora_fin,
            'nombre_visitante' => $cita->nombre_visitante,
            'motivo'           => $cita->motivo,
            'estado'           => $cita->estado,
            'notas'            => $cita->notas,
            'created_at'       => $cita->created_at,
            'usuario'          => $cita->usuario?->only(['id', 'name', 'email']) ?? null,
            'visitante'        => $cita->visitante ?? null,
        ]);
    }

    public function update(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $cita = Cita::where('id_user', $identity->id)->find($id);
        if (!$cita) return response()->json(['message' => 'Cita no encontrada'], 404);

        $request->validate([
            'fecha'        => 'sometimes|required|date',
            'hora_inicio'  => 'sometimes|required',
            'hora_fin'     => 'sometimes|required|after:hora_inicio',
            'visitantes'   => 'sometimes|required|array|min:1',
            'visitantes.*' => 'required|integer',
            'motivo'       => 'nullable|string|max:255',
            'estado'       => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'        => 'nullable|string',
            'con_vehiculo' => 'nullable|boolean',
        ], [
            'visitantes.required' => 'Debes seleccionar al menos un proveedor a invitar.',
            'visitantes.min'      => 'Debes seleccionar al menos un proveedor a invitar.',
            'hora_fin.after'      => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'      => 'La fecha es obligatoria.',
        ]);

        $fecha      = $request->fecha       ?? $cita->fecha;
        $horaInicio = $request->hora_inicio ?? $cita->hora_inicio;
        $horaFin    = $request->hora_fin    ?? $cita->hora_fin;

        if ($this->VisitaService->hayCruceEnAgendaDeAnfitrion($identity->id, $fecha, $horaInicio, $horaFin, excludeId: $id)) {
            return response()->json([
                'message' => 'Ya tienes una cita agendada en ese horario.',
                'errores' => [],
            ], 422);
        }

        $this->VisitaService->resetQueueIndex();
        $resultado = $this->VisitaService->actualizarCitaAnfitrion($request, $cita, $identity);

        // ── Con visitantes nuevos devuelve array ──
        if (is_array($resultado)) {
            ['citasActualizadas' => $citasActualizadas, 'errores' => $errores] = $resultado;

            if (empty($citasActualizadas)) {
                return response()->json(['message' => 'No se pudo actualizar ninguna cita.', 'errores' => $errores], 422);
            }

            return response()->json([
                'message' => count($citasActualizadas) . ' cita(s) actualizada(s) con éxito.',
                'citas'   => $citasActualizadas,
                'errores' => $errores,
            ]);
        }

        // ── Update simple devuelve el Cita fresco ──
        return response()->json([
            'message' => 'Cita actualizada con éxito.',
            'cita'    => $resultado,
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $cita = Cita::where('id_user', $identity->id)->find($id);
        if (!$cita) return response()->json(['message' => 'Cita no encontrada'], 404);

        $cita->delete();

        return response()->json(['message' => 'Cita eliminada correctamente'], 200);
    }

    public function updateEstado(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $request->validate([
            'estado' => 'required|in:pendiente,confirmada,cancelada',
        ]);

        $cita = Cita::where(function ($q) use ($identity) {
            $q->where('id_user', $identity->id)
              ->orWhere('id_visitante', $identity->id);
        })->find($id);

        if (!$cita) return response()->json(['message' => 'Cita no encontrada'], 404);

        $this->VisitaService->resetQueueIndex();
        ['sin_cambio' => $sinCambio, 'cita' => $cita] = $this->VisitaService->actualizarEstado($request, $cita, $identity);

        if ($sinCambio) {
            return response()->json(['message' => 'El estado ya era el mismo.', 'cita' => $cita]);
        }

        return response()->json(['message' => 'Estado actualizado.', 'cita' => $cita]);
    }

    /* ══════════════════════════════════════════════
     |  ADMIN
     ══════════════════════════════════════════════ */

    public function indexAdmin(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $usuariosPermitidos  = [252, 235, 264, 256];
        $rolesPermitidos     = [1];
        $subrolesPermitidos  = [6, 15, 16];

        $esUsuarioPermitido = in_array($identity->id, $usuariosPermitidos);
        $tieneRol           = ModelHasRole::where('firebird_identity_id', $identity->id)->whereIn('role_id', $rolesPermitidos)->exists();
        $tieneSubrol        = ModelHasRole::where('firebird_identity_id', $identity->id)->whereIn('subrol_id', $subrolesPermitidos)->exists();

        if (!($esUsuarioPermitido && $tieneRol && $tieneSubrol)) {
            return response()->json(['message' => 'Sin permisos'], 403);
        }

        return response()->json(
            $this->VisitaService->listarTodasLasCitas()
        );
    }

    public function UsuariosPermitidosParaAllUsers()
    {
        return response()->json(
            $this->resolverUsuariosPorCombinaciones([
                ['role_id' => 8, 'subrol_id' => null],
            ])
        );
    }

    /* ══════════════════════════════════════════════
     |  PROVEEDORES
     ══════════════════════════════════════════════ */

    public function storeProveedor(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $request->validate([
            'fecha'        => 'required|date',
            'hora_inicio'  => 'required',
            'hora_fin'     => 'required|after:hora_inicio',
            'visitantes'   => 'required|array|min:1',
            'visitantes.*' => 'required|integer',
            'motivo'       => 'nullable|string|max:255',
            'notas'        => 'nullable|string',
            'con_vehiculo' => 'nullable|boolean',
        ], [
            'visitantes.required' => 'Debes seleccionar al menos un usuario a visitar.',
            'visitantes.min'      => 'Debes seleccionar al menos un usuario a visitar.',
            'hora_fin.after'      => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'      => 'La fecha es obligatoria.',
        ]);

        if ($this->VisitaService->hayCruceEnAgendaDeAnfitrion($identity->id, $request->fecha, $request->hora_inicio, $request->hora_fin)) {
            return response()->json([
                'message' => 'Ya tienes una cita agendada en ese horario.',
                'errores' => [],
            ], 422);
        }

        $this->VisitaService->resetQueueIndex();
        ['citasCreadas' => $citasCreadas, 'errores' => $errores] = $this->VisitaService->crearCitasProveedor($request, $identity);

        if (empty($citasCreadas)) {
            return response()->json(['message' => 'No se pudo crear ninguna cita.', 'errores' => $errores], 422);
        }

        return response()->json([
            'message' => count($citasCreadas) . ' cita(s) registrada(s) con éxito.',
            'citas'   => $citasCreadas,
            'errores' => $errores,
        ], 201);
    }

    public function updateProveedor(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $request->validate([
            'ids'          => 'required|array|min:1',
            'ids.*'        => 'required|integer',
            'fecha'        => 'required|date',
            'hora_inicio'  => 'required',
            'hora_fin'     => 'required|after:hora_inicio',
            'visitantes'   => 'required|array|min:1',
            'visitantes.*' => 'required|integer',
            'motivo'       => 'nullable|string|max:255',
            'estado'       => 'nullable|in:pendiente,confirmada,cancelada',
            'notas'        => 'nullable|string',
            'con_vehiculo' => 'nullable|boolean',
        ], [
            'ids.required'        => 'Se requieren los ids de las citas a actualizar.',
            'visitantes.required' => 'Debes seleccionar al menos un usuario a visitar.',
            'visitantes.min'      => 'Debes seleccionar al menos un usuario a visitar.',
            'hora_fin.after'      => 'La hora de fin debe ser mayor que la hora de inicio.',
            'fecha.required'      => 'La fecha es obligatoria.',
        ]);

        $this->VisitaService->resetQueueIndex();
        ['citasCreadas' => $citasCreadas, 'errores' => $errores] = $this->VisitaService->actualizarCitasProveedor($request, $identity);

        if (empty($citasCreadas)) {
            return response()->json(['message' => 'No se pudo actualizar ninguna cita.', 'errores' => $errores], 422);
        }

        return response()->json([
            'message' => count($citasCreadas) . ' cita(s) actualizada(s) con éxito.',
            'citas'   => $citasCreadas,
            'errores' => $errores,
        ]);
    }

    public function destroyProveedor(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();

        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        $eliminadas = Cita::whereIn('id', $request->ids)
            ->where('id_user', $identity->id)
            ->delete();

        return response()->json(['message' => "{$eliminadas} cita(s) eliminada(s)."]);
    }

    public function UsuariosPermitidosParaProvedores()
    {
        return response()->json(
            $this->resolverUsuariosPorCombinaciones([
                ['role_id' => 3, 'subrol_id' => 7],
                ['role_id' => 3, 'subrol_id' => 9],
                ['role_id' => 3, 'subrol_id' => 10],
                ['role_id' => 1, 'subrol_id' => 12],
                ['role_id' => 1, 'subrol_id' => 7],
                ['role_id' => 1, 'subrol_id' => 3],
                ['role_id' => 1, 'subrol_id' => 6],
                ['role_id' => 1, 'subrol_id' => 14],
                ['role_id' => 1, 'subrol_id' => 13],
                ['role_id' => 3, 'subrol_id' => null],
            ])
        );
    }

    /* ══════════════════════════════════════════════
     |  HELPER PRIVADO — consulta de roles
     ══════════════════════════════════════════════ */

    private function resolverUsuariosPorCombinaciones(array $combinaciones): \Illuminate\Support\Collection
    {
        $identities = ModelHasRole::with(['firebirdIdentity.firebirdUser', 'role', 'subrol'])
            ->where(function ($q) use ($combinaciones) {
                foreach ($combinaciones as $combo) {
                    $q->orWhere(function ($subQ) use ($combo) {
                        $subQ->where('role_id', $combo['role_id']);
                        is_null($combo['subrol_id'])
                            ? $subQ->whereNull('subrol_id')
                            : $subQ->where('subrol_id', $combo['subrol_id']);
                    });
                }
            })
            ->get();

        return $identities->map(function ($item) {
            $identity = $item->firebirdIdentity;
            $user     = $identity?->firebirdUser;

            return [
                'id'      => $identity?->id,
                'user_id' => $user?->ID,
                'nombre'  => $user?->NOMBRE ?? 'Sin nombre',
                'correo'  => $user?->CORREO ?? null,
                'rol'     => ['id' => $item->role?->id,   'nombre' => $item->role?->nombre],
                'subrol'  => ['id' => $item->subrol?->id, 'nombre' => $item->subrol?->nombre],
            ];
        });
    }


 /* ══════════════════════════════════════════════
     |  INDEX
     ══════════════════════════════════════════════ */
 
    public function indexJunta(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();
 
        return response()->json(
            $this->juntaService->listarJuntasDeIdentity($identity)
        );
    }
 
    /* ══════════════════════════════════════════════
     |  STORE
     ══════════════════════════════════════════════ */
 
    public function storeJunta(Request $request)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();
 
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
 
        $fechaHoraInicio = new \DateTime("{$request->fecha}T{$request->hora_inicio}:00");
        if ($fechaHoraInicio <= new \DateTime()) {
            return response()->json([
                'message' => 'No puedes agendar una junta en una fecha u hora que ya pasó.',
                'errores' => [],
            ], 422);
        }
 
        if ($this->juntaService->hayCruceEnAgendaDeOrganizador($identity->id, $request->fecha, $request->hora_inicio, $request->hora_fin)) {
            return response()->json([
                'message' => 'Ya tienes una cita o junta agendada en ese horario.',
                'errores' => [],
            ], 422);
        }
 
        $this->juntaService->resetQueueIndex();
        ['juntasCreadas' => $juntasCreadas, 'errores' => $errores] = $this->juntaService->crearJuntas($request, $identity);
 
        if (empty($juntasCreadas)) {
            return response()->json(['message' => 'No se pudo crear ninguna junta.', 'errores' => $errores], 422);
        }
 
        return response()->json([
            'message' => count($juntasCreadas) . ' junta(s) registrada(s) con éxito.',
            'juntas'  => $juntasCreadas,
            'errores' => $errores,
        ], 201);
    }
 
    /* ══════════════════════════════════════════════
     |  UPDATE
     ══════════════════════════════════════════════ */
 
    public function updateJunta(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();
 
        $junta = Cita::where('cita_type_id', 2)
            ->where('id_user', $identity->id)
            ->find($id);
 
        if (!$junta) return response()->json(['message' => 'Junta no encontrada'], 404);
 
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
 
        $fecha      = $request->fecha       ?? $junta->fecha;
        $horaInicio = $request->hora_inicio ?? $junta->hora_inicio;
        $horaFin    = $request->hora_fin    ?? $junta->hora_fin;
        $idsGrupo   = $this->juntaService->obtenerIdsDelGrupo($identity->id, $junta);
 
        if ($this->juntaService->hayCruceEnAgendaDeOrganizador($identity->id, $fecha, $horaInicio, $horaFin, excludeIds: $idsGrupo->toArray())) {
            return response()->json([
                'message' => 'Ya tienes una cita o junta agendada en ese horario.',
                'errores' => [],
            ], 422);
        }
 
        $this->juntaService->resetQueueIndex();
        $resultado = $this->juntaService->actualizarJunta($request, $junta, $identity);
 
        // ── Con participantes nuevos devuelve array ──
        if (is_array($resultado)) {
            ['juntasActualizadas' => $juntasActualizadas, 'errores' => $errores] = $resultado;
 
            if (empty($juntasActualizadas)) {
                return response()->json(['message' => 'No se pudo actualizar ninguna junta.', 'errores' => $errores], 422);
            }
 
            return response()->json([
                'message' => count($juntasActualizadas) . ' junta(s) actualizada(s) con éxito.',
                'juntas'  => $juntasActualizadas,
                'errores' => $errores,
            ]);
        }
 
        // ── Update simple devuelve la Cita fresca ──
        return response()->json([
            'message' => 'Junta actualizada con éxito.',
            'junta'   => $resultado,
        ]);
    }
 
    /* ══════════════════════════════════════════════
     |  DESTROY
     ══════════════════════════════════════════════ */
 
    public function destroyJunta(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();
 
        $junta = Cita::where('cita_type_id', 2)
            ->where('id_user', $identity->id)
            ->find($id);
 
        if (!$junta) return response()->json(['message' => 'Junta no encontrada'], 404);
 
        $this->juntaService->resetQueueIndex();
        $this->juntaService->eliminarJunta($request, $junta, $identity);
 
        return response()->json(['message' => 'Junta eliminada correctamente'], 200);
    }
 
    /* ══════════════════════════════════════════════
     |  UPDATE ESTADO
     ══════════════════════════════════════════════ */
 
    public function updateEstadoJunta(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();
 
        $request->validate([
            'estado' => 'required|in:pendiente,confirmada,cancelada',
        ]);
 
        $junta = Cita::where('cita_type_id', 2)
            ->where(function ($q) use ($identity) {
                $q->where('id_user', $identity->id)
                  ->orWhere('id_visitante', $identity->id);
            })
            ->find($id);
 
        if (!$junta) return response()->json(['message' => 'Junta no encontrada'], 404);
 
        $this->juntaService->resetQueueIndex();
        ['sin_cambio' => $sinCambio, 'junta' => $junta] = $this->juntaService->actualizarEstado($request, $junta, $identity);
 
        if ($sinCambio) {
            return response()->json(['message' => 'El estado ya era el mismo.', 'junta' => $junta]);
        }
 
        return response()->json(['message' => 'Estado actualizado.', 'junta' => $junta]);
    }
 
    /* ══════════════════════════════════════════════
     |  UPDATE ASISTENCIA
     ══════════════════════════════════════════════ */
 
    public function updateAsistencia(Request $request, $id)
    {
        $identity = $this->getIdentityFromToken($request);
        if (!$identity) return $this->unauthorized();
 
        $request->validate([
            'asistencia' => 'required|in:pendiente,confirmada,rechazada',
        ]);
 
        // Solo el participante (id_visitante) puede confirmar su asistencia
        $junta = Cita::where('cita_type_id', 2)
            ->where('id_visitante', $identity->id)
            ->find($id);
 
        if (!$junta) return response()->json(['message' => 'Junta no encontrada o no eres participante'], 404);
 
        $this->juntaService->resetQueueIndex();
        ['junta' => $junta] = $this->juntaService->actualizarAsistencia($request, $junta, $identity);
 
        return response()->json([
            'message' => 'Asistencia actualizada.',
            'junta'   => $junta,
        ]);
    }
    
}