<?php

namespace App\Services\Checador;

use App\Events\ChecadorMovimientoRegistrado;
use App\Models\ChecadorEntrada;
use App\Models\ChecadorPermiso;
use App\Models\ChecadorRegistro;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ChecadorGuardiaService
{
    public function __construct(protected ChecadorScanService $scanService) {}

    /**
     * Busca personas por nombre o clave para el checador manual de
     * seguridad (sin QR). Solo regresa identidades que SÍ pueden checar
     * (aptasParaChecador), junto con su estado actual del día para que
     * el guardia sepa de un vistazo qué movimiento le toca registrar.
     */
    public function buscarPorNombre(string $termino): Collection
    {
        $termino = trim($termino);
        $terminoUpper = Str::upper($termino);

        $empleados = Users::query()
            ->where(function ($query) use ($termino, $terminoUpper) {
                if (ctype_digit($termino)) {
                    $query->where('ID', $termino);
                }

                $query->orWhere('NOMBRE', 'like', "%{$terminoUpper}%");
            })
            ->limit(20)
            ->get();

        return $empleados
            ->map(function ($empleado) {
                $idFirebird = trim((string) $empleado->ID);

                $identity = UserFirebirdIdentity::where('firebird_user_clave', $idFirebird)
                    ->aptasParaChecador()
                    ->first();

                if (! $identity) {
                    return null;
                }

                $photo = trim((string) ($empleado->PHOTO ?? ''));

                return [
                    'identity_id' => $identity->id,
                    'nombre' => trim((string) $empleado->NOMBRE),
                    // Campo PHOTO de Firebird (ej. "photos/users.jpg"). El front
                    // (ChecadorService.userPhoto) ya sabe anteponerle el apiBase
                    // si viene como ruta relativa, o usarla tal cual si es una URL completa.
                    'foto' => $photo !== '' ? $photo : null,
                    'firebird_empresa' => $identity->firebird_empresa,
                    'numero_credencial' => $identity->numero_credencial,
                    'estado_actual' => $this->estadoActual($identity),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Último movimiento del día de una identidad y qué se le sugiere
     * registrar a continuación (entrada, salida, inicio o fin de permiso),
     * más el contexto que el guardia necesita para decidir con confianza:
     * área, jefe, puntualidad estimada contra su turno y excepciones que
     * ya tiene autorizadas (para no pedirle de más).
     */
   public function estadoActual(UserFirebirdIdentity $identity): array
    {
        $hoy = now()->toDateString();
        $ahora = now();

        $ultimoRegistro = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->orderByDesc('fecha_hora')
            ->first();

        $permisoActivo = $this->permisoDisponibleHoy($identity, $ahora, $hoy, $ultimoRegistro);

        [$area, $jefe, $jefeAux] = $this->areaYJefe($identity);

        $siguiente = $this->siguienteMovimiento($ultimoRegistro, $permisoActivo);

        $base = [
            'area' => $area,
            'jefe' => $jefe,
            'jefe_aux' => $jefeAux,
            'puntualidad' => $this->puntualidadHoy($identity, $ahora, $hoy),
            'flags_extraordinarios' => $this->flagsExtraordinarios($identity),
            'permisos_hoy' => $this->permisosHoy($identity, $hoy),
            'restriccion_salida' => $siguiente === 'salida'
                ? $this->restriccionSalida($identity, $ahora, $hoy, $permisoActivo)
                : null,
            'restriccion_entrada' => $siguiente === 'entrada'
                ? $this->restriccionEntrada($identity, $hoy, $permisoActivo)
                : null,
            'ultimo_tipo' => $ultimoRegistro->tipo ?? null,
            'ultima_hora' => $ultimoRegistro->hora ?? null,
            'siguiente_movimiento_sugerido' => $siguiente,
            'permiso_disponible' => $this->permisoInfo($permisoActivo),
        ];

        return $base;
    }

    private function siguienteMovimiento(?ChecadorRegistro $ultimoRegistro, ?ChecadorPermiso $permisoActivo): string
    {
        if (! $ultimoRegistro) {
            return 'entrada';
        }

        return match (true) {
            $ultimoRegistro->tipo === 'Inicio de permiso' => 'Fin de permiso',
            in_array($ultimoRegistro->tipo, ['entrada', 'Fin de permiso'], true) && $permisoActivo !== null => 'Inicio de permiso',
            in_array($ultimoRegistro->tipo, ['entrada', 'Fin de permiso'], true) => 'salida',
            $ultimoRegistro->tipo === 'salida' => 'entrada',
            default => 'entrada',
        };
    }

    /**
     * Área y nombre del jefe directo, según user_puestos. El nombre del
     * jefe vive en Firebird (Users.NOMBRE), así que lo resolvemos vía su
     * firebird_user_clave, igual que hacemos con el empleado buscado.
     *
     * @return array{0: string|null, 1: string|null} [area, jefe]
     */
    private function areaYJefe(UserFirebirdIdentity $identity): array
    {
        $puesto = DB::table('user_puestos')
            ->where('user_firebird_identity_id', $identity->id)
            ->where('activo', 1)
            ->first();

        if (! $puesto) {
            return [null, null, null];
        }

        $area = $puesto->area_id
            ? DB::table('areas')->where('id', $puesto->area_id)->value('nombre')
            : null;

        $jefe = $puesto->jefe_id
            ? $this->nombrePorIdentity((int) $puesto->jefe_id)
            : null;

        $jefeAux = $puesto->jefe_aux_id
            ? $this->nombrePorIdentity((int) $puesto->jefe_aux_id)
            : null;

        return [$area, $jefe, $jefeAux];
    }

    private function nombrePorIdentity(?int $identityId): ?string
    {
        if (! $identityId) {
            return null;
        }

        $clave = UserFirebirdIdentity::where('id', $identityId)->value('firebird_user_clave');

        if (! $clave) {
            return null;
        }

        $nombre = Users::where('ID', $clave)->value('NOMBRE');

        return $nombre ? trim((string) $nombre) : null;
    }

  /**
     * Puntualidad del día.
     *
     * - Si YA existe un registro real de entrada hoy, se usa ese dato
     *   congelado (hora_entrada / minutos_retardo / es_retardo tal comoq
     *   quedó calculado por ChecadorScanService al momento de checar).
     *   Así ya no sigue "avanzando" el retardo con el paso del tiempo.
     * - Si TODAVÍA no ha checado, es un preview contra la hora actual,
     *   pero ahora sí toma en cuenta la tolerancia de
     *   checador_permisos_extraordinarios antes de marcar "retardo".
     */
    private function puntualidadHoy(UserFirebirdIdentity $identity, Carbon $ahora, string $hoy): ?array
    {
        // 1) Ya checó entrada hoy -> usar el dato congelado, no recalcular.
        $entradaHoy = ChecadorEntrada::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->orderByDesc('hora_entrada')
            ->first();

        if ($entradaHoy) {
            $horaCorta = $entradaHoy->hora_programada
                ? substr($entradaHoy->hora_programada, 0, 5)
                : null;
            $horaEntradaCorta = substr($entradaHoy->hora_entrada, 0, 5);

            if ($entradaHoy->es_retardo && $entradaHoy->minutos_retardo > 0) {
                return [
                    'hora_programada' => $horaCorta,
                    'diferencia_minutos' => $entradaHoy->minutos_retardo,
                    'estado' => 'retardo',
                    'mensaje' => "Entró a las {$horaEntradaCorta} con {$entradaHoy->minutos_retardo} min de retardo"
                        .($horaCorta ? " (hora programada {$horaCorta})" : ''),
                ];
            }

            return [
                'hora_programada' => $horaCorta,
                'diferencia_minutos' => 0,
                'estado' => 'a_tiempo',
                'mensaje' => "Entró a las {$horaEntradaCorta}"
                    .($horaCorta ? " (hora programada {$horaCorta})" : ''),
            ];
        }

        // 2) Aún no checa -> preview contra la hora actual, respetando tolerancia.
        $userTurno = DB::table('user_turnos')
            ->where('user_firebird_identity_id', $identity->id)
            ->where('status_id', 1)
            ->first();

        if (! $userTurno) {
            return null;
        }

        $turnoDia = DB::table('turno_dias')
            ->where('turno_id', $userTurno->turno_id)
            ->where('dia_semana', $ahora->dayOfWeek)
            ->first();

        if (! $turnoDia || ! $turnoDia->es_laborable || ! $turnoDia->hora_entrada) {
            return [
                'hora_programada' => null,
                'diferencia_minutos' => 0,
                'estado' => 'sin_turno',
                'mensaje' => 'Hoy no tiene turno laboral programado.',
            ];
        }

        $horaCorta = substr($turnoDia->hora_entrada, 0, 5);
        $programada = Carbon::parse($ahora->toDateString().' '.$turnoDia->hora_entrada);
        $diffMin = intdiv($ahora->timestamp - $programada->timestamp, 60);

        if ($diffMin > 0) {
            $ext = DB::table('checador_permisos_extraordinarios')
                ->where('user_firebird_identity_id', $identity->id)
                ->where('activo', 1)
                ->first();

            if ($ext && $this->dentroDeTolerancia($ext, $diffMin, $ahora)) {
                return [
                    'hora_programada' => $horaCorta,
                    'diferencia_minutos' => $diffMin,
                    'estado' => 'a_tiempo',
                    'mensaje' => "Dentro de su tolerancia autorizada de entrada (hora programada {$horaCorta})",
                ];
            }

            return [
                'hora_programada' => $horaCorta,
                'diferencia_minutos' => $diffMin,
                'estado' => 'retardo',
                'mensaje' => "Entrando con {$diffMin} min de retardo (hora programada {$horaCorta})",
            ];
        }

        if ($diffMin < -5) {
            $anticipo = abs($diffMin);

            return [
                'hora_programada' => $horaCorta,
                'diferencia_minutos' => $diffMin,
                'estado' => 'anticipado',
                'mensaje' => "Llegando {$anticipo} min antes de su hora ({$horaCorta})",
            ];
        }

        return [
            'hora_programada' => $horaCorta,
            'diferencia_minutos' => $diffMin,
            'estado' => 'a_tiempo',
            'mensaje' => "A tiempo (hora programada {$horaCorta})",
        ];
    }

     /**
     * Determina si, dado un retardo de $diffMin minutos, la identidad
     * sigue dentro de su tolerancia autorizada de entrada tardía.
     */
    private function dentroDeTolerancia(object $ext, int $diffMin, Carbon $ahora): bool
    {
        if (! (int) $ext->puede_entrar_tarde) {
            return false;
        }

        // Si hay hora límite y ya la pasó, ya no aplica la tolerancia
        // sin importar si es ilimitada o por minutos.
        if ($ext->hora_limite) {
            $limite = Carbon::parse($ahora->toDateString().' '.$ext->hora_limite);
            if ($ahora->greaterThan($limite)) {
                return false;
            }
        }

        if ((int) $ext->tolerancia_ilimitada === 1) {
            return true;
        }

        if ($ext->tolerancia_minutos !== null) {
            return $diffMin <= (int) $ext->tolerancia_minutos;
        }

        return false;
    }


    /**
     * Solo regresa las excepciones que YA tiene autorizadas (para que el
     * guardia sepa que no necesita pedirle permiso por eso). Si no tiene
     * ninguna excepción activa, regresa un arreglo vacío y el front no
     * muestra nada.
     */
    private function flagsExtraordinarios(UserFirebirdIdentity $identity): array
    {
        $ext = DB::table('checador_permisos_extraordinarios')
            ->where('user_firebird_identity_id', $identity->id)
            ->where('activo', 1)
            ->first();

        if (! $ext) {
            return [];
        }

        $flags = [];

        if ((int) $ext->puede_salir_cualquier_momento === 1) {
            $flags[] = 'Puede salir sin permiso en cualquier momento del día';
        }

        if ((int) $ext->salir_comer_necesita_permiso === 0) {
            $flags[] = 'Puede salir a comer sin permiso';
        }

        if ((int) $ext->puede_entrar_tarde === 1) {
            if ((int) $ext->tolerancia_ilimitada === 1) {
                $flags[] = 'Tolerancia de entrada ilimitada'.
                    ($ext->hora_limite ? ' hasta las '.substr($ext->hora_limite, 0, 5) : '');
            } elseif ((int) $ext->tolerancia_minutos > 0) {
                $flags[] = "Tolerancia de entrada: {$ext->tolerancia_minutos} min".
                    ($ext->hora_limite ? ' (límite '.substr($ext->hora_limite, 0, 5).')' : '');
            }
        }

        if (! empty(trim($ext->permiso_extraordinario_otro ?? ''))) {
            $flags[] = trim($ext->permiso_extraordinario_otro);
        }

        return $flags;
    }

    /**
     * Versión simplificada de resolverPermisoActivo (ChecadorScanService) para
     * mostrarle al guardia si hay un permiso aprobado y sin usar todavía.
     */
    private function permisoDisponibleHoy(
        UserFirebirdIdentity $identity,
        Carbon $ahora,
        string $hoy,
        ?ChecadorRegistro $ultimoRegistro
    ): ?ChecadorPermiso {
        // Ya está dentro de un permiso abierto (Inicio de permiso sin cerrar)
        if ($ultimoRegistro && $ultimoRegistro->tipo === 'Inicio de permiso' && $ultimoRegistro->checador_permiso_id) {
            return ChecadorPermiso::with('catalogo')->find($ultimoRegistro->checador_permiso_id);
        }

        return ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->where(function ($q) use ($ahora) {
                $q->whereNull('hora_inicio')
                    ->orWhere(function ($q2) use ($ahora) {
                        $q2->where('hora_inicio', '<=', $ahora->toTimeString())
                            ->where(function ($q3) use ($ahora) {
                                $q3->whereNull('hora_fin')
                                    ->orWhere('hora_fin', '>=', $ahora->toTimeString());
                            });
                    });
            })
            ->whereDoesntHave('registros', function ($q) use ($hoy) {
                $q->where('fecha', $hoy)->where('tipo', 'Fin de permiso');
            })
            ->with('catalogo')
            ->orderByRaw('hora_inicio IS NULL')
            ->first();
    }

    private function permisoInfo(?ChecadorPermiso $permiso): ?array
    {
        if (! $permiso) {
            return null;
        }

        return [
            'id' => $permiso->id,
            'tipo' => $permiso->catalogo->nombre ?? 'Permiso',
            'clave' => $permiso->catalogo->clave ?? null,
        ];
    }

    /**
     * Registra la checada manual capturada por el guardia. Reutiliza
     * ChecadorScanService::registrarChecadaManual (misma lógica de
     * tolerancias/permisos que usa el QR), y deja constancia en
     * observaciones de que fue un registro manual de seguridad: motivo
     * (sin QR / sin datos / credencial robada o extraviada) y quién de
     * seguridad lo capturó.
     */
    public function registrar(int $identityId, ?string $motivoManual, ?int $guardiaId, array $meta = []): array
    {
        $resultado = $this->scanService->registrarChecadaManual($identityId, $meta);

        $registro = $resultado['registro'] ?? null;

        if ($registro instanceof ChecadorRegistro) {
            $nota = $this->construirNotaGuardia($motivoManual, $guardiaId);

            $registro->observaciones = trim(
                ($registro->observaciones ? $registro->observaciones.' | ' : '').$nota
            );
            $registro->save();

            // 👇 esto es lo que faltaba
            $usuario = $resultado['usuario'] ?? null; // ajusta según cómo venga en tu $resultado

            broadcast(new ChecadorMovimientoRegistrado(
                identityId: $identityId,
                nombre: $usuario->nombre ?? null,
                foto: $usuario->foto ?? null,
                tipo: $registro->tipo,
                hora: $registro->hora,
                firebirdEmpresa: $registro->firebird_empresa ?? null,
                metodo: $registro->metodo ?? 'manual',
            ));
        }

        return $resultado;
    }

    private function construirNotaGuardia(?string $motivoManual, ?int $guardiaId): string
    {
        $partes = ['Registrado manualmente por seguridad'];

        if ($motivoManual) {
            $partes[] = "Motivo: {$motivoManual}";
        }

        if ($guardiaId) {
            $partes[] = "Guardia ID: {$guardiaId}";
        }

        return implode(' — ', $partes);
    }

    /**
     * Todos los permisos que aplican para hoy (aprobados, pendientes, rechazados),
     * para el resumen del día del guardia. A diferencia de permisoDisponibleHoy(),
     * este incluye los que ya se usaron y se cerraron (ej. comida ya regresada).
     */
    private function permisosHoy(UserFirebirdIdentity $identity, string $hoy): array
    {
        return ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->with('catalogo')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn (ChecadorPermiso $permiso) => [
                'id' => $permiso->id,
                'tipo' => $permiso->catalogo->nombre ?? 'Permiso',
                'estado' => $permiso->estado,
                'hora_inicio' => $this->horaCorta($permiso->hora_inicio),
                'hora_fin' => $this->horaCorta($permiso->hora_fin),
            ])
            ->values()
            ->all();
    }

    private function horaCorta($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->format('H:i');
        }

        if (is_string($valor) && str_contains($valor, ' ')) {
            return Carbon::parse($valor)->format('H:i');
        }

        return substr((string) $valor, 0, 5);
    }

    /**
     * Determina si en este momento el siguiente movimiento sugerido "salida"
     * sería rechazado por ChecadorScanService::bloquearSalidaSinPermiso, y por
     * qué, para que el guardia lo sepa ANTES de intentar registrar (en vez de
     * enterarse con un error 403 después de darle clic).
     */
    private function restriccionSalida(
        UserFirebirdIdentity $identity,
        Carbon $ahora,
        string $hoy,
        ?ChecadorPermiso $permisoActivo
    ): ?array {
        if ($permisoActivo) {
            return null;
        }

        $puedeSalirLibre = $identity->permisoExtraordinario
            && $identity->permisoExtraordinario->activo
            && $identity->permisoExtraordinario->puede_salir_cualquier_momento;

        $puedeSalirAntes = $identity->permisoExtraordinario
            && $identity->permisoExtraordinario->activo
            && $identity->permisoExtraordinario->salir_antes;

        if ($puedeSalirLibre || $puedeSalirAntes) {
            return null;
        }

        $userTurno = DB::table('user_turnos')
            ->where('user_firebird_identity_id', $identity->id)
            ->where('status_id', 1)
            ->first();

        if (! $userTurno) {
            return null;
        }

        $turnoDia = DB::table('turno_dias')
            ->where('turno_id', $userTurno->turno_id)
            ->where('dia_semana', $ahora->dayOfWeek)
            ->first();

        if (! $turnoDia || ! $turnoDia->es_laborable || ! $turnoDia->hora_salida) {
            return null;
        }

        $horaSalidaProgramada = Carbon::parse($hoy.' '.$turnoDia->hora_salida);

        // Sin la excepción, el bloqueo dura hasta la hora exacta de salida —
        // ya no hay ventana de 60 min de gracia.
        if ($ahora->greaterThanOrEqualTo($horaSalidaProgramada)) {
            return null;
        }

        $minutosFaltantes = (int) $ahora->diffInMinutes($horaSalidaProgramada);
        $horaCorta = substr($turnoDia->hora_salida, 0, 5);

        $permisoComidaHoy = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->whereDate('fecha_inicio', $hoy)
            ->whereHas('catalogo', fn ($q) => $q->where('clave', 'COMIDA'))
            ->first();

        $mensaje = match (true) {
            ! $permisoComidaHoy => 'No puede salir antes de su hora de salida sin un permiso autorizado por su jefe.',
            $permisoComidaHoy->estado === 'pendiente' => 'Su permiso de comida aún no ha sido autorizado por su jefe.',
            default => 'Ya usó su permiso de comida de hoy. Para volver a salir necesita un permiso autorizado por su jefe.',
        };

        return [
            'bloqueada' => true,
            'motivo' => $mensaje,
            'hora_salida_programada' => $horaCorta,
            'minutos_para_poder_salir' => $minutosFaltantes,
        ];
    }

    /**
     * Determina si en este momento el siguiente movimiento sugerido "entrada"
     * sería rechazado por ChecadorScanService::verificarNoExcedeEntradaYSalidaNormales,
     * para que el guardia lo sepa ANTES de intentarlo. Solo aplica cuando la
     * identidad ya completó su ciclo normal de entrada+salida de hoy (sin
     * permiso de por medio) y no tiene la excepción de entrar/salir libre.
     */
    private function restriccionEntrada(
        UserFirebirdIdentity $identity,
        string $hoy,
        ?ChecadorPermiso $permisoActivo
    ): ?array {
        if ($permisoActivo) {
            return null;
        }

        $puedeEntrarSalirLibre = $identity->permisoExtraordinario
            && $identity->permisoExtraordinario->activo
            && $identity->permisoExtraordinario->puede_salir_cualquier_momento;

        if ($puedeEntrarSalirLibre) {
            return null;
        }

        $registrosNormalesHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->whereNull('checador_permiso_id')
            ->get();

        $entradasNormales = $registrosNormalesHoy->whereIn('tipo', ['entrada', 'Fin de permiso'])->count();
        $salidasNormales = $registrosNormalesHoy->whereIn('tipo', ['salida', 'Inicio de permiso'])->count();

        if ($entradasNormales >= 1 && $salidasNormales >= 1) {
            throw new RuntimeException(
                'Ya registraste tu entrada y tu salida de hoy. Ya cumpliste tu jornada — para volver a entrar necesitas un permiso autorizado por tu jefe.',
                409
            );
        }

        return null;
    }
}