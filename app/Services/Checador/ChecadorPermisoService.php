<?php

namespace App\Services\Checador;

use App\Models\ChecadorCatalogoPermiso;
use App\Models\ChecadorPermiso;
use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChecadorPermisoService
{
    /** Duración por defecto (min) si el catálogo no trae una configurada. */
    private const DURACION_DEFAULT_MINUTOS = 60;
    private const CLAVES_PAGO_TIEMPO = ['EXTRA', 'PERSONAL', 'TRAMITE', 'MEDICO'];

    public function catalogo()
    {
        return ChecadorCatalogoPermiso::activos()->get();
    }

    public function solicitar(array $data): ChecadorPermiso
    {
        $identity = UserFirebirdIdentity::findOrFail($data['user_firebird_identity_id']);
        $catalogo = ChecadorCatalogoPermiso::findOrFail($data['checador_catalogo_permiso_id']);

        if ($catalogo->clave === 'COMIDA') {
            throw new RuntimeException(
                'El permiso de comida se genera automáticamente al registrar tu entrada, no se solicita manualmente.',
                422
            );
        }

        $yaTienePermisoExtra = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('estado', '!=', 'rechazado')
            ->whereHas('catalogo', fn($q) => $q->where('clave', '!=', 'COMIDA'))
            ->where(function ($q) use ($data) {
                $q->whereBetween('fecha_inicio', [$data['fecha_inicio'], $data['fecha_fin']])
                    ->orWhereBetween('fecha_fin', [$data['fecha_inicio'], $data['fecha_fin']])
                    ->orWhere(function ($q2) use ($data) {
                        $q2->where('fecha_inicio', '<=', $data['fecha_inicio'])
                            ->where('fecha_fin', '>=', $data['fecha_fin']);
                    });
            })
            ->exists();

        if ($yaTienePermisoExtra) {
            throw new RuntimeException(
                'Ya tienes un permiso solicitado o aprobado para ese periodo. Solo se permite un permiso extra (aparte del de comida) por día.',
                422
            );
        }

        $noRegresa = (bool) ($data['no_regresa'] ?? false);

        $permisoData = [
            'user_firebird_identity_id' => $identity->id,
            'checador_catalogo_permiso_id' => $catalogo->id,
            'firebird_empresa' => $identity->firebird_empresa,
            'tipo' => $data['tipo'] ?? 'normal',
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fin' => $noRegresa ? null : ($data['hora_fin'] ?? null),
            'no_regresa' => $noRegresa,
            'todo_el_dia' => (bool) ($data['todo_el_dia'] ?? false),
            'motivo' => $data['motivo'],
            'estado' => 'solicitado',
            'estado_rh' => 'no_aplica',
            'estado_jefe' => 'pendiente',
        ];

        // ── Pago de tiempo ──
        if (in_array($catalogo->clave, self::CLAVES_PAGO_TIEMPO, true)) {
            $horarioDia = $this->horarioDelDia($identity, Carbon::parse($data['fecha_inicio']));

            $permisoData['tipo_pago_tiempo'] = $data['tipo_pago_tiempo'] ?? null;
            $permisoData['minutos_ausencia'] = $this->calcularMinutosAusencia(
                $noRegresa,
                $data['hora_inicio'] ?? null,
                $noRegresa ? null : ($data['hora_fin'] ?? null),
                $horarioDia
            );
            $permisoData['fecha_reposicion'] = $data['fecha_reposicion'] ?? null;
            $permisoData['hora_inicio_reposicion'] = $data['hora_inicio_reposicion'] ?? null;
            $permisoData['hora_fin_reposicion'] = $data['hora_fin_reposicion'] ?? null;
            $permisoData['justificacion_pago_tiempo'] = $data['justificacion_pago_tiempo'] ?? null;
        }

        $permiso = ChecadorPermiso::create($permisoData);

        $this->autoAprobarSiNoTieneJefe($permiso, $identity);

        return $permiso->fresh()->load('catalogo');
    }

    // ══════════════════════════════════════════════════════════════
    //  RESOLVER (con auto-generación de permiso para día de descanso)
    // ══════════════════════════════════════════════════════════════

    public function resolver(int $permisoId, string $rol, array $data): ChecadorPermiso
    {
        if ($rol !== 'jefe') {
            throw new RuntimeException('Solo el jefe puede resolver permisos.', 422);
        }

        $permiso = ChecadorPermiso::with('identity')->find($permisoId);
        if (!$permiso) {
            throw new RuntimeException('Permiso no encontrado', 404);
        }
        if (in_array($permiso->estado, ['aprobado', 'rechazado'], true)) {
            throw new RuntimeException('Este permiso ya fue resuelto anteriormente', 409);
        }
        if ($permiso->estado_jefe !== 'pendiente') {
            throw new RuntimeException('El jefe ya se pronunció sobre este permiso', 409);
        }

        $jefeIdActual = $this->jefeIdDe($permiso->identity);
        if ($jefeIdActual && (int) $data['aprobado_por'] !== (int) $jefeIdActual) {
            throw new RuntimeException('Solo el jefe asignado puede resolver este permiso', 403);
        }

        $permiso->estado_jefe = $data['estado'];
        $permiso->aprobado_por_jefe = $data['aprobado_por'];
        $permiso->fecha_resolucion_jefe = now();
        $permiso->comentarios_jefe = $data['comentarios_aprobador'] ?? null;
        $permiso->estado = match ($permiso->estado_jefe) {
            'rechazado' => 'rechazado',
            'aprobado' => 'aprobado',
            default => 'pendiente',
        };
        $permiso->save();

        // ── Auto-generar permiso de reposición si es día de descanso ──
        if (
            $permiso->estado === 'aprobado'
            && $permiso->tipo_pago_tiempo === 'dia_descanso'
            && $permiso->fecha_reposicion
        ) {
            $this->generarPermisoReposicionDiaDescanso($permiso);
        }

        if ($permiso->estado === 'aprobado' && $permiso->fecha_reposicion) {
            if (in_array($permiso->tipo_pago_tiempo, ['dia_descanso', 'tiempo_por_tiempo'], true)) {
                $this->generarPermisoReposicionSiAplica($permiso);
            }
        }

        return $permiso->fresh()->load('catalogo', 'aprobadorJefe');
    }


    /**
     * Genera un permiso FUNCION para la fecha de reposición SOLO si ese día
     * no es laborable en el turno del empleado (ej. sábado en turno L-V).
     * Si el día ya es laborable normal, no hace falta permiso: el empleado
     * simplemente checa normal y el motor de pago de tiempo hace el resto.
     */
    private function generarPermisoReposicionSiAplica(ChecadorPermiso $permisoOriginal): void
    {
        $identity = UserFirebirdIdentity::with('turnoActivo.turno')->find($permisoOriginal->user_firebird_identity_id);
        if (!$identity) {
            return;
        }

        $fecha = Carbon::parse($permisoOriginal->fecha_reposicion);
        $horario = $this->horarioDelDia($identity, $fecha);

        // Si el día YA es laborable normal, no generamos nada: solo checa normal
        // y ChecadorPagoTiempoService detecta la deuda por fecha_reposicion.
        if ($horario !== null) {
            return;
        }

        $this->crearPermisoFuncionReposicion($permisoOriginal, $fecha->toDateString());
    }

    private function crearPermisoFuncionReposicion(ChecadorPermiso $permisoOriginal, string $fechaReposicion): void
    {
        $catalogoFuncion = ChecadorCatalogoPermiso::where('clave', 'FUNCION')->first();
        if (!$catalogoFuncion) {
            Log::warning('REPOSICION_SIN_CATALOGO_FUNCION', ['permiso_id' => $permisoOriginal->id]);
            return;
        }

        $yaExiste = ChecadorPermiso::where('user_firebird_identity_id', $permisoOriginal->user_firebird_identity_id)
            ->where('permiso_origen_id', $permisoOriginal->id)
            ->whereDate('fecha_inicio', $fechaReposicion)
            ->exists();

        if ($yaExiste) {
            return;
        }

        $reposicion = ChecadorPermiso::create([
            'user_firebird_identity_id' => $permisoOriginal->user_firebird_identity_id,
            'checador_catalogo_permiso_id' => $catalogoFuncion->id,
            'firebird_empresa' => $permisoOriginal->firebird_empresa,
            'tipo' => 'normal',
            'fecha_inicio' => $fechaReposicion,
            'fecha_fin' => $fechaReposicion,
            'no_regresa' => false,
            'motivo' => "Reposición por permiso #{$permisoOriginal->id} — {$permisoOriginal->motivo}",
            'estado' => 'aprobado',
            'estado_rh' => 'no_aplica',
            'estado_jefe' => 'aprobado',
            'aprobado_por_jefe' => $permisoOriginal->aprobado_por_jefe,
            'fecha_resolucion_jefe' => now(),
            'comentarios_jefe' => 'Auto-generado: reposición de tiempo.',
            'permiso_origen_id' => $permisoOriginal->id,
        ]);

        Log::info('REPOSICION_GENERADA', [
            'permiso_original_id' => $permisoOriginal->id,
            'reposicion_id' => $reposicion->id,
            'fecha_reposicion' => $fechaReposicion,
        ]);
    }



    // ══════════════════════════════════════════════════════════════
    //  HORARIO DEL DÍA (con DB facade que faltaba)
    // ══════════════════════════════════════════════════════════════

    private function horarioDelDia(UserFirebirdIdentity $identity, Carbon $fecha): ?array
    {
        $userTurno = DB::table('user_turnos')
            ->where('user_firebird_identity_id', $identity->id)
            ->where('status_id', 1)
            ->first();

        if (!$userTurno) {
            return null;
        }

        $diaSemana = (int) $fecha->format('w');

        $turnoDia = DB::table('turno_dias')
            ->where('turno_id', $userTurno->turno_id)
            ->where('dia_semana', $diaSemana)
            ->first();

        if (!$turnoDia || !(bool) ($turnoDia->es_laborable ?? false)) {
            return null;
        }

        $turno = DB::table('turnos')->find($userTurno->turno_id);

        $horaEntrada = $turnoDia->hora_entrada ?? $turno->hora_entrada ?? null;
        $horaSalida = $turnoDia->hora_salida ?? $turno->hora_salida ?? null;

        if (!$horaEntrada || !$horaSalida) {
            return null;
        }

        return ['hora_entrada' => $horaEntrada, 'hora_salida' => $horaSalida];
    }

    // ══════════════════════════════════════════════════════════════
    //  CÁLCULO DE MINUTOS DE AUSENCIA
    // ══════════════════════════════════════════════════════════════

    private function calcularMinutosAusencia(
        bool $noRegresa,
        ?string $horaInicioPermiso,
        ?string $horaFinPermiso,
        ?array $horarioDia
    ): int {
        if ($noRegresa) {
            if (!$horarioDia) {
                return 0;
            }
            $desde = $horaInicioPermiso
                ? Carbon::parse($horaInicioPermiso)
                : Carbon::parse($horarioDia['hora_entrada']);
            $hastaSalida = Carbon::parse($horarioDia['hora_salida']);
            return $desde->diffInMinutes($hastaSalida);
        }

        if ($horaInicioPermiso && $horaFinPermiso) {
            return Carbon::parse($horaInicioPermiso)->diffInMinutes(Carbon::parse($horaFinPermiso));
        }

        if ($horarioDia) {
            return Carbon::parse($horarioDia['hora_entrada'])
                ->diffInMinutes(Carbon::parse($horarioDia['hora_salida']));
        }

        return 0;
    }

    // ══════════════════════════════════════════════════════════════
    //  COMIDA AUTOMÁTICA
    // ══════════════════════════════════════════════════════════════

    public function crearPermisoComidaAutomaticoSiAplica(
        UserFirebirdIdentity $identity,
        string $fecha,
        ?array $horariosHoy
    ): ?ChecadorPermiso {
        if (empty($horariosHoy['hora_salida'])) {
            return null;
        }

        $yaExiste = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->whereHas('catalogo', fn($q) => $q->where('clave', 'COMIDA'))
            ->whereDate('fecha_inicio', $fecha)
            ->where('estado', '!=', 'rechazado')
            ->exists();

        if ($yaExiste) {
            return null;
        }

        $catalogo = ChecadorCatalogoPermiso::where('clave', 'COMIDA')->first();
        if (!$catalogo) {
            return null;
        }

        $permiso = ChecadorPermiso::create([
            'user_firebird_identity_id' => $identity->id,
            'checador_catalogo_permiso_id' => $catalogo->id,
            'firebird_empresa' => $identity->firebird_empresa,
            'tipo' => 'normal',
            'fecha_inicio' => $fecha,
            'fecha_fin' => $fecha,
            'hora_inicio' => null,
            'hora_fin' => null,
            'no_regresa' => false,
            'motivo' => 'Hora de comida',
            'estado' => 'pendiente',
            'estado_rh' => 'no_aplica',
            'estado_jefe' => 'pendiente',
        ]);

        $this->autoAprobarSiNoTieneJefe($permiso, $identity);

        Log::info('PERMISO_COMIDA_GENERADO', [
            'permiso_id' => $permiso->id,
            'identity_id' => $identity->id,
            'fecha' => $fecha,
            'estado' => $permiso->fresh()->estado,
        ]);

        return $permiso->fresh();
    }

    public function iniciarUsoPermiso(ChecadorPermiso $permiso, Carbon $ahora): void
    {
        if ($permiso->hora_inicio !== null) {
            return;
        }

        $duracionMin = $permiso->catalogo->duracion_default_minutos ?? self::DURACION_DEFAULT_MINUTOS;

        $permiso->hora_inicio = $ahora->toTimeString();
        $permiso->hora_fin = $ahora->copy()->addMinutes($duracionMin)->toTimeString();
        $permiso->save();
    }

    // ══════════════════════════════════════════════════════════════
    //  AUTO-APROBAR / BANDEJAS / HISTORIAL
    // ══════════════════════════════════════════════════════════════

    private function autoAprobarSiNoTieneJefe(ChecadorPermiso $permiso, UserFirebirdIdentity $identity): void
    {
        if ($this->jefeIdDe($identity)) {
            return;
        }

        $permiso->estado_jefe = 'aprobado';
        $permiso->fecha_resolucion_jefe = now();
        $permiso->comentarios_jefe = 'Autoaprobado: la identidad no tiene jefe asignado.';
        $permiso->estado = 'aprobado';
        $permiso->save();
    }

    public function pendientesJefe(int $jefeId)
    {
        return ChecadorPermiso::where('estado_jefe', 'pendiente')
            ->whereHas('identity.puestoActivo', fn($q) => $q->where('jefe_id', $jefeId))
            ->with(['identity.firebirdUser', 'identity.puestoActivo.area', 'identity.puestoActivo.puesto', 'catalogo'])
            ->orderBy('fecha_inicio')
            ->paginate(20);
    }

    public function historial(int $identityId)
    {
        return ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->with('catalogo')
            ->orderByDesc('fecha_inicio')
            ->paginate(20);
    }

    public function historialEquipo(int $jefeId)
    {
        return ChecadorPermiso::whereHas('identity.puestoActivo', fn($q) => $q->where('jefe_id', $jefeId))
            ->with(['identity.firebirdUser', 'identity.puestoActivo.area', 'catalogo'])
            ->orderByDesc('fecha_inicio')
            ->paginate(100);
    }

    private function jefeIdDe(UserFirebirdIdentity $identity): ?int
    {
        return $identity->puestoActivo()->first()?->jefe_id;
    }


    /**
     * Cierra el uso real de un permiso cuando el empleado checa su regreso
     * ("Fin de permiso"). Corrige el hora_fin "estimado" (duracion_default_minutos)
     * que se puso al iniciar, reemplazándolo por la hora REAL de regreso.
     */
    public function finalizarUsoPermiso(ChecadorPermiso $permiso, Carbon $ahora): void
    {
        // Si nunca se "inició" en automático (permisos de día completo, de horario
        // fijo capturado por el usuario, etc.), no tocamos nada.
        if ($permiso->hora_inicio === null) {
            return;
        }

        $horaInicioStr = $this->horaComoString($permiso->hora_inicio);
        $horaInicio = Carbon::parse($permiso->fecha_inicio->toDateString() . ' ' . $horaInicioStr);

        // Protección: si por algún motivo llega un registro desordenado
        // (fin antes que inicio), no muevas el hora_fin hacia atrás.
        if ($ahora->lessThan($horaInicio)) {
            return;
        }

        $permiso->hora_fin = $ahora->toTimeString();
        $permiso->save();

        Log::info('PERMISO_FINALIZADO_HORA_REAL', [
            'permiso_id' => $permiso->id,
            'hora_inicio' => $horaInicioStr,
            'hora_fin_real' => $permiso->hora_fin,
        ]);
    }

    /**
     * hora_inicio/hora_fin pueden llegar como Carbon (cast datetime) o string.
     * Normaliza a "H:i:s" para poder concatenar con la fecha sin reventar.
     */
    private function horaComoString($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->format('H:i:s');
        }

        if (is_string($valor) && str_contains($valor, ' ')) {
            return Carbon::parse($valor)->format('H:i:s');
        }

        return (string) $valor;
    }
}