<?php

namespace App\Services\Checador;

use App\Events\ChecadorMovimientoRegistrado;
use App\Models\ChecadorAccessQrCode;
use App\Models\ChecadorCatalogoPermiso;
use App\Models\ChecadorEntrada;
use App\Models\ChecadorPermiso;
use App\Models\ChecadorRegistro;
use App\Models\ChecadorSalida;
use App\Models\Turno;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ChecadorScanService
{
    private const TOLERANCIA_ENTRADA_MINUTOS = 15;

    private const TOLERANCIA_SALIDA_MINUTOS = 30;

    private const VENTANA_BLOQUEO_PERMISO_MINUTOS = 60;

    private const VENTANA_AJUSTE_SALIDA_MINUTOS = 30;

    private const TOLERANCIA_ANTICIPACION_PERMISO_MINUTOS = 15;

    private ChecadorPagoTiempoService $pagoTiempoService;

    private ChecadorPermisoService $permisoService;

    public function __construct(
        ?ChecadorPermisoService $permisoService = null,
        ?ChecadorPagoTiempoService $pagoTiempoService = null
    ) {
        $this->permisoService = $permisoService ?? new ChecadorPermisoService;
        $this->pagoTiempoService = $pagoTiempoService ?? new ChecadorPagoTiempoService;
    }

    // ============================================================
    // QR
    // ============================================================

    public function obtenerActivo(int $identityId): ?ChecadorAccessQrCode
    {
        return ChecadorAccessQrCode::where('user_firebird_identity_id', $identityId)
            ->where('activo', true)
            ->first();
    }

    public function generar(int $identityId): ChecadorAccessQrCode
    {
        $identity = UserFirebirdIdentity::with('firebirdUser')->findOrFail($identityId);

        if ($identity->excluir_checador) {
            throw new RuntimeException(
                'Esta cuenta es de sistema y no puede generar QR de checador.',
                422
            );
        }

        $qr = ChecadorAccessQrCode::obtenerOCrearParaIdentity(
            $identityId,
            ['nombre' => $identity->firebirdUser->NOMBRE ?? null],
            $identity->firebird_empresa
        );

        Log::info('QR_GENERADO_O_REUTILIZADO', [
            'identity_id' => $identityId,
            'qr_id' => $qr->id,
            'creado' => $qr->wasRecentlyCreated,
        ]);

        return $qr;
    }

    public function revocar(int $identityId): ?ChecadorAccessQrCode
    {
        $qr = $this->obtenerActivo($identityId);
        if (! $qr) {
            return null;
        }

        $qr->update(['activo' => false]);
        Log::warning('QR_REVOCADO', ['identity_id' => $identityId, 'qr_id' => $qr->id]);

        return $qr;
    }

    // ============================================================
    // Checadas
    // ============================================================

    public function registrarChecada(string $token, array $meta = []): array
    {
        $qr = ChecadorAccessQrCode::where('token', $token)
            ->where('activo', true)
            ->with(['identity.turnoActivo.turno.turnoDias', 'identity.permisoExtraordinario'])
            ->first();

        if (! $qr) {
            throw new RuntimeException('QR inválido o inactivo', 404);
        }

        $identity = $qr->identity;
        if (! $identity) {
            throw new RuntimeException('Identidad asociada al QR no encontrada', 404);
        }

        if ($identity->excluir_checador) {
            throw new RuntimeException('Esta cuenta no puede registrar checadas.', 422);
        }

        $resultado = $this->procesarChecada($identity, $qr->firebird_empresa, 'qr', $meta);
        $qr->update(['ultima_lectura' => Carbon::now()]);
        $resultado['usuario_nombre'] = $qr->payload['nombre'] ?? $resultado['usuario_nombre'];

        return $resultado;
    }

    public function registrarChecadaManual(int $identityId, array $meta = []): array
    {
        $identity = UserFirebirdIdentity::with(['firebirdUser', 'turnoActivo.turno.turnoDias', 'permisoExtraordinario'])->find($identityId);
        if (! $identity) {
            throw new RuntimeException('Identidad no encontrada', 404);
        }

        if ($identity->excluir_checador) {
            throw new RuntimeException('Esta cuenta no puede registrar checadas.', 422);
        }

        $resultado = $this->procesarChecada($identity, $identity->firebird_empresa, 'manual', $meta);
        $resultado['usuario_nombre'] = $identity->firebirdUser->NOMBRE ?? null;

        return $resultado;
    }

    private function procesarChecada(UserFirebirdIdentity $identity, ?string $firebirdEmpresa, string $metodo, array $meta): array
    {
        $status = $this->obtenerStatusEmpleadoNoi($identity);
        // if ($status !== null && $status !== 'A') {
        //     throw new RuntimeException('Acceso denegado: el empleado no se encuentra activo', 403);
        // }

        $now = Carbon::now();
        $hoy = $now->toDateString();

        // Permiso extraordinario de la identidad (si tiene). Se usa para
        // decidir tolerancia de entrada, generación de permiso de comida,
        // y si puede saltarse los bloqueos de salida sin permiso.
        $permisoExtra = $identity->permisoExtraordinario;
        $puedeEntrarSalirLibre = (bool) ($permisoExtra && $permisoExtra->activo && $permisoExtra->puede_salir_cualquier_momento);
        $puedeSalirAntes = (bool) ($permisoExtra && $permisoExtra->activo && $permisoExtra->salir_antes);
        // Permisos que no sean de comida (comida se valida aparte, más abajo).
        $this->verificarPermisoNoComidaPendiente($identity, $now, $hoy);

        [$turnoId, $horariosHoy] = $this->resolverTurnoYHorarios($identity, $hoy);

        $ultimoRegistro = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->orderByDesc('fecha_hora')
            ->first();

        $esPrimerRegistroDelDia = ! $ultimoRegistro;

        if (! $ultimoRegistro) {
            $this->permisoService->crearPermisoComidaAutomaticoSiAplica($identity, $hoy, $horariosHoy);
        }

        $permisoComidaHoy = ChecadorPermiso::with('catalogo')
            ->where('user_firebird_identity_id', $identity->id)
            ->whereDate('fecha_inicio', $hoy)
            ->whereHas('catalogo', fn ($q) => $q->where('clave', 'COMIDA'))
            ->first();

        $yaUsoComidaHoy = false;
        if ($permisoComidaHoy) {
            $yaUsoComidaHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->where('checador_permiso_id', $permisoComidaHoy->id)
                ->whereIn('tipo', ['Inicio de permiso', 'Fin de permiso'])
                ->exists();
        }

        $permisoActivo = $this->resolverPermisoActivo($identity, $now, $hoy, $ultimoRegistro, $yaUsoComidaHoy);

        if ($permisoActivo && $this->permisoBloqueadoPorCierreDeTurno($permisoActivo, $now, $horariosHoy)) {
            $permisoActivo = null;
        }

        $estabaAfuera = $ultimoRegistro && in_array($ultimoRegistro->tipo, ['salida', 'Inicio de permiso'], true);

        if (! $ultimoRegistro || $estabaAfuera) {
            if ($ultimoRegistro && $ultimoRegistro->tipo === 'Inicio de permiso') {
                $tipo = 'Fin de permiso';
            } else {
                $tipo = 'entrada';
                if ($permisoActivo && $permisoActivo->hora_inicio === null) {
                    $permisoActivo = null;
                }
            }
        } elseif ($permisoActivo) {
            $tipo = 'Inicio de permiso';
        } else {
            if (! $puedeEntrarSalirLibre && ! $puedeSalirAntes) {
                $this->bloquearSalidaSinPermiso($identity, $now, $horariosHoy, $permisoComidaHoy, $yaUsoComidaHoy);
            }
            $tipo = 'salida';
        }

        if (! $permisoActivo && ! $puedeEntrarSalirLibre) {
            $this->verificarNoExcedeEntradaYSalidaNormales($identity, $hoy);
        }

        if ($tipo === 'Inicio de permiso' && $permisoActivo) {
            $this->permisoService->iniciarUsoPermiso($permisoActivo, $now);
        }

        if ($tipo === 'Fin de permiso' && $permisoActivo) {
            $this->permisoService->finalizarUsoPermiso($permisoActivo, $now);
        }

        $esEntrada = in_array($tipo, ['entrada', 'Fin de permiso'], true);

        // Regla 10: para identidades marcadas, si checan salida unos minutos antes de su
        // horario, se registra la hora programada en vez de la hora real.
        $horaParaRegistro = ($tipo === 'salida')
            ? $this->calcularHoraRegistroConAjuste($identity, $now, $horariosHoy, $puedeSalirAntes)
            : $now;

        $puntualidad = $esEntrada
            ? $this->calcularPuntualidadEntrada($now, $horariosHoy, $permisoActivo, $identity, $hoy)
            : $this->calcularPuntualidadSalida($horaParaRegistro, $horariosHoy, $permisoActivo, $identity, $hoy, null, $puedeEntrarSalirLibre || $puedeSalirAntes);

        $esCierreDeTurno = false;
        if ($tipo === 'salida' && ! empty($horariosHoy['hora_salida'])) {
            $horaSalidaProg = Carbon::parse($hoy.' '.$horariosHoy['hora_salida']);
            if ($horariosHoy['sale_dia_siguiente'] ?? false) {
                $horaSalidaProg->addDay();
            }
            $esCierreDeTurno = $now->greaterThanOrEqualTo(
                $horaSalidaProg->copy()->subMinutes(self::VENTANA_BLOQUEO_PERMISO_MINUTOS)
            );
        }

        $jornada = null;
        if (! $esEntrada) {
            $ultimaEntradaHoy = ChecadorEntrada::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->orderByDesc('hora_entrada')
                ->first();

            if ($ultimaEntradaHoy) {
                $horaEntradaReal = Carbon::parse($hoy.' '.$ultimaEntradaHoy->hora_entrada);
                $jornada = $this->calcularHorasJornada($horaParaRegistro, $horaEntradaReal, $identity->id, $hoy, $horariosHoy);
            }
        }

        $observaciones = $permisoActivo
            ? "{$permisoActivo->motivo}"
            : null;

        $autorizadaLibre = $puedeEntrarSalirLibre
            && ! $permisoActivo
            && in_array($tipo, ['entrada', 'salida'], true);

        DB::beginTransaction();

        try {
            $registro = ChecadorRegistro::create([
                'user_firebird_identity_id' => $identity->id,
                'firebird_empresa' => $firebirdEmpresa,
                'turno_id' => $turnoId,
                'checador_permiso_id' => $permisoActivo->id ?? null,
                'tipo' => $tipo,
                'fecha' => $hoy,
                'hora' => $horaParaRegistro->toTimeString(),
                'fecha_hora' => $horaParaRegistro,
                'metodo' => $metodo,
                'ip_address' => $meta['ip'] ?? null,
                'dispositivo' => substr((string) ($meta['user_agent'] ?? ''), 0, 250),
                'valido' => true,
                'observaciones' => $observaciones,
            ]);

            if ($esEntrada) {
                ChecadorEntrada::create([
                    'checador_registro_id' => $registro->id,
                    'user_firebird_identity_id' => $identity->id,
                    'firebird_empresa' => $firebirdEmpresa,
                    'turno_id' => $turnoId,
                    'fecha' => $hoy,
                    'hora_entrada' => $horaParaRegistro->toTimeString(),
                    'hora_programada' => $puntualidad['hora_programada'],
                    'minutos_retardo' => $puntualidad['minutos_retardo'],
                    'es_retardo' => $puntualidad['es_retardo'],
                ]);
            } else {
                $ultimaEntrada = ChecadorEntrada::where('user_firebird_identity_id', $identity->id)
                    ->where('fecha', $hoy)
                    ->orderByDesc('hora_entrada')
                    ->first();

                ChecadorSalida::create([
                    'checador_registro_id' => $registro->id,
                    'checador_entrada_id' => $ultimaEntrada->id ?? null,
                    'user_firebird_identity_id' => $identity->id,
                    'firebird_empresa' => $firebirdEmpresa,
                    'turno_id' => $turnoId,
                    'fecha' => $hoy,
                    'hora_salida' => $horaParaRegistro->toTimeString(),
                    'hora_programada' => $puntualidad['hora_programada'],
                    'minutos_anticipacion' => $puntualidad['minutos_anticipacion'],
                    'horas_extra' => $puntualidad['horas_extra'],
                ]);
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ERROR_REGISTRAR_CHECADA', ['identity_id' => $identity->id, 'error' => $e->getMessage()]);
            throw new RuntimeException('Error al registrar checada', 500);
        }
        $esPrimerRegistroDelDia = ! $ultimoRegistro;
        $datosEmpleado = $this->obtenerDatosEmpleadoNoi($identity);

        event(new ChecadorMovimientoRegistrado(
            identityId: $identity->id,
            nombre: $identity->firebirdUser->NOMBRE ?? null,
            foto: $identity->firebirdUser->PHOTO ?? null,
            tipo: $tipo,
            hora: $horaParaRegistro->format('H:i'),
            firebirdEmpresa: $firebirdEmpresa,
            metodo: $metodo,
        ));

        return [
            'registro' => $registro,
            'tipo' => $tipo,
            'es_primer_registro_dia' => $esPrimerRegistroDelDia,
            'es_cierre_de_turno' => $esCierreDeTurno,
            'usuario_nombre' => $identity->firebirdUser->NOMBRE ?? null,
            'usuario_photo' => $identity->firebirdUser->PHOTO ?? null,
            'TB' => $datosEmpleado['tbRow'],
            'DEPTO_NOI' => $datosEmpleado['deptoRow'],
            'PUESTO_NOI' => $datosEmpleado['puestoRow'],
            'USER_PUESTO' => $this->obtenerPuestoAreaLocal($identity),
            'permiso' => $permisoActivo,
            'autorizada_libre' => $autorizadaLibre,
            'puntualidad' => $puntualidad,
            'jornada' => $jornada,
        ];
    }

    /**
     * Busca el turno activo de la identidad y, si no tiene horario de hoy resuelto,
     * intenta cargarlo desde el turno base o desde el turno por defecto del área.
     *
     * @return array{0: ?int, 1: ?array} [turnoId, horariosHoy]
     */
    private function resolverTurnoYHorarios(UserFirebirdIdentity $identity, string $hoy): array
    {
        $turnoActivo = $identity->turnoActivo;
        $turnoId = $turnoActivo->turno_id ?? null;
        $horariosHoy = $turnoActivo?->getHorariosHoy();

        if ($turnoId && $horariosHoy) {
            return [$turnoId, $horariosHoy];
        }

        $turno = $turnoId ? Turno::find($turnoId) : $this->resolverTurnoPorDefecto($identity);

        if (! $turno) {
            throw new RuntimeException(
                'No se pudo registrar la checada: el empleado no tiene turno configurado.',
                422
            );
        }

        $horariosHoy = [
            'hora_entrada' => $turno->hora_entrada,
            'hora_salida' => $turno->hora_salida,
            'hora_inicio_comida' => $turno->hora_inicio_comida,
            'hora_fin_comida' => $turno->hora_fin_comida,
            'entra_dia_anterior' => (bool) $turno->entra_dia_anterior,
            'sale_dia_siguiente' => (bool) $turno->sale_dia_siguiente,
        ];

        return [$turno->id, $horariosHoy];
    }

    /**
     * Encuentra, si existe, el permiso aprobado que aplica a este momento: o bien el que
     * ya se venía usando (Inicio de permiso sin cerrar), o el primero disponible entre los
     * aprobados de hoy que aún no se haya usado.
     */
    private function resolverPermisoActivo(
        UserFirebirdIdentity $identity,
        Carbon $now,
        string $hoy,
        ?ChecadorRegistro $ultimoRegistro,
        bool $yaUsoComidaHoy
    ): ?ChecadorPermiso {
        if ($ultimoRegistro && $ultimoRegistro->tipo === 'Inicio de permiso' && $ultimoRegistro->checador_permiso_id) {
            return ChecadorPermiso::with('catalogo')->find($ultimoRegistro->checador_permiso_id);
        }

        $ahoraConTolerancia = $now->copy()
            ->addMinutes(self::TOLERANCIA_ANTICIPACION_PERMISO_MINUTOS)
            ->toTimeString();

        $permisosDisponibles = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->where(function ($q) use ($now, $ahoraConTolerancia) {
                $q->whereNull('hora_inicio')
                    ->orWhere(function ($q2) use ($now, $ahoraConTolerancia) {
                        $q2->where('hora_inicio', '<=', $ahoraConTolerancia)
                            ->where(function ($q3) use ($now) {
                                $q3->whereNull('hora_fin')
                                    ->orWhere('hora_fin', '>=', $now->toTimeString());
                            });
                    });
            })
            ->with('catalogo')
            ->orderByRaw('hora_inicio IS NULL')
            ->get();

        foreach ($permisosDisponibles as $permiso) {
            $esComida = $permiso->catalogo && $permiso->catalogo->clave === 'COMIDA';

            if ($esComida && $yaUsoComidaHoy) {
                continue;
            }

            $yaRegresadoDeEstePermiso = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->where('checador_permiso_id', $permiso->id)
                ->where('tipo', 'Fin de permiso')
                ->exists();

            if ($yaRegresadoDeEstePermiso) {
                continue;
            }

            return $permiso;
        }

        return null;
    }

    /**
     * Regla 4 y 9: si el empleado no tiene un permiso aprobado vigente y todavía no le toca
     * su hora de salida, no se le permite salir. El mensaje cambia según por qué no tiene permiso.
     */
    private function bloquearSalidaSinPermiso(
        UserFirebirdIdentity $identity,
        Carbon $now,
        ?array $horariosHoy,
        ?ChecadorPermiso $permisoComidaHoy,
        bool $yaUsoComidaHoy
    ): void {
        if (empty($horariosHoy['hora_salida'])) {
            return;
        }

        $horaSalidaProgramada = Carbon::parse($now->toDateString().' '.$horariosHoy['hora_salida']);
        if ($horariosHoy['sale_dia_siguiente'] ?? false) {
            $horaSalidaProgramada->addDay();
        }

        $inicioVentanaCierre = $horaSalidaProgramada->copy()->subMinutes(self::VENTANA_BLOQUEO_PERMISO_MINUTOS);

        // La ventana de gracia (salir hasta 60 min antes sin permiso) SOLO aplica
        // a identidades con la excepción "puede_salir_cualquier_momento" activa.
        // Para el resto, aunque ya esté cerca de su hora de salida, sigue
        // necesitando permiso hasta que sea literalmente su hora.
        $tieneVentanaDeGracia = $identity->permisoExtraordinario
            && $identity->permisoExtraordinario->activo
            && $identity->permisoExtraordinario->puede_salir_cualquier_momento;

        if ($tieneVentanaDeGracia && $now->greaterThanOrEqualTo($inicioVentanaCierre)) {
            return;
        }

        // Sin ventana de gracia: solo se libera exactamente a su hora de salida (o después).
        if (! $tieneVentanaDeGracia && $now->greaterThanOrEqualTo($horaSalidaProgramada)) {
            return;
        }

        if (! $permisoComidaHoy) {
            throw new RuntimeException(
                'No puedes salir antes de tu hora de salida sin un permiso autorizado por tu jefe.',
                403
            );
        }

        if ($permisoComidaHoy->estado === 'pendiente' && ! $yaUsoComidaHoy) {
            throw new RuntimeException(
                'Tu permiso para ir a comer todavía no ha sido autorizado por tu jefe. Espera su aprobación antes de salir.',
                403
            );
        }

        throw new RuntimeException(
            'Ya usaste tu permiso de comida de hoy. Para volver a salir necesitas un permiso autorizado por tu jefe, o espera a tu hora de salida.',
            403
        );
    }

    /**
     * Regla 10: si la identidad tiene el ajuste activado y checa su salida dentro de la
     * ventana previa a su hora programada, se registra la hora programada en vez de la real.
     */
    private function calcularHoraRegistroConAjuste(
        UserFirebirdIdentity $identity,
        Carbon $now,
        ?array $horariosHoy,
        bool $puedeSalirAntes = false
    ): Carbon {
        $tieneAjustePuntual = (bool) ($identity->checador_ajuste_salida_puntual ?? false);

        if ((! $tieneAjustePuntual && ! $puedeSalirAntes) || empty($horariosHoy['hora_salida'])) {
            return $now;
        }

        $horaSalidaProgramada = Carbon::parse($now->toDateString().' '.$horariosHoy['hora_salida']);
        if ($horariosHoy['sale_dia_siguiente'] ?? false) {
            $horaSalidaProgramada->addDay();
        }

        if ($now->greaterThan($horaSalidaProgramada)) {
            return $now; // ya se pasó de su hora, se registra la real
        }

        // salir_antes: sin importar qué tan temprano se vaya, se registra
        // como si hubiera salido a su hora — cumplió su jornada completa.
        if ($puedeSalirAntes) {
            return $horaSalidaProgramada;
        }

        $minutosAntes = $now->diffInMinutes($horaSalidaProgramada);

        return $minutosAntes <= self::VENTANA_AJUSTE_SALIDA_MINUTOS ? $horaSalidaProgramada : $now;
    }

    private function verificarNoExcedeEntradaYSalidaNormales(UserFirebirdIdentity $identity, string $hoy): void
    {
        $registrosNormalesHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->whereNull('checador_permiso_id')
            ->get();

        $entradasNormales = $registrosNormalesHoy->whereIn('tipo', ['entrada', 'Fin de permiso'])->count();
        $salidasNormales = $registrosNormalesHoy->whereIn('tipo', ['salida', 'Inicio de permiso'])->count();

        if ($entradasNormales >= 1 && $salidasNormales >= 1) {
            throw new RuntimeException(
                'Ya registraste tu entrada y tu salida de hoy. Si necesitas volver a checar, se requiere un permiso aprobado.',
                409
            );
        }
    }

    private function calcularPuntualidadEntrada(Carbon $now, ?array $horariosHoy, ?ChecadorPermiso $permiso, UserFirebirdIdentity $identity, string $hoy): array
    {
        $base = ['hora_programada' => null, 'minutos_retardo' => 0, 'es_retardo' => false, 'minutos_anticipacion' => 0, 'horas_extra' => 0];

        if ($permiso && $permiso->hora_fin) {
            $horaFin = $this->soloHora($permiso->hora_fin);
            $horaLimite = Carbon::parse($permiso->fecha_inicio->toDateString().' '.$horaFin);
            $diff = $now->getTimestamp() - $horaLimite->getTimestamp();
            $base['hora_programada'] = $horaFin;
            $base['minutos_retardo'] = $diff > 0 ? (int) floor($diff / 60) : 0;
            $base['es_retardo'] = $base['minutos_retardo'] > 0;

            return $base;
        }

        if (! $horariosHoy || empty($horariosHoy['hora_entrada'])) {
            return $base;
        }

        $horaProgramada = Carbon::parse($now->toDateString().' '.$horariosHoy['hora_entrada']);
        if ($horariosHoy['entra_dia_anterior'] ?? false) {
            $horaProgramada->subDay();
        }

        $diff = $now->getTimestamp() - $horaProgramada->getTimestamp();

        // Llegó antes de su hora → posible abono a deuda
        if ($diff < 0) {
            $minutosAnticipacion = (int) floor(abs($diff) / 60);
            $permisoDeuda = $this->pagoTiempoService->permisoPendientePago($identity, $hoy);
            if ($permisoDeuda) {
                $this->pagoTiempoService->abonar($permisoDeuda, $identity, $minutosAnticipacion, 'entrada_anticipada', $hoy);
            }
            $base['hora_programada'] = $horariosHoy['hora_entrada'];

            return $base;
        }

        $minutosTarde = (int) floor($diff / 60);
        $toleranciaMinutos = $this->toleranciaEntradaMinutos($identity, $horaProgramada, $now);

        $base['hora_programada'] = $horariosHoy['hora_entrada'];

        if ($toleranciaMinutos === null) {
            // Tolerancia ilimitada (permiso extraordinario): nunca marca retardo.
            return $base;
        }

        $minutosRetardo = max(0, $minutosTarde - $toleranciaMinutos);
        $base['minutos_retardo'] = $minutosRetardo;
        $base['es_retardo'] = $minutosRetardo > 0;

        return $base;
    }

    /**
     * Minutos de tolerancia para checar entrada tarde sin marcar retardo.
     * Regresa null si la tolerancia es ilimitada (nunca marca retardo).
     *
     * Prioridad:
     *  1. Sin permiso extraordinario activo, o puede_entrar_tarde = false
     *     → tolerancia default (self::TOLERANCIA_ENTRADA_MINUTOS = 15 min).
     *  2. tolerancia_ilimitada = true → sin límite, nunca marca retardo.
     *  3. hora_limite configurada → tope absoluto definido por RH, se
     *     convierte a minutos de tolerancia sobre la hora programada del turno.
     *  4. tolerancia_minutos configurada → se usa tal cual.
     */
    private function toleranciaEntradaMinutos(UserFirebirdIdentity $identity, Carbon $horaProgramada, Carbon $now): ?int
    {
        $permisoExtra = $identity->permisoExtraordinario;

        if (! $permisoExtra || ! $permisoExtra->activo || ! $permisoExtra->puede_entrar_tarde) {
            return self::TOLERANCIA_ENTRADA_MINUTOS;
        }

        if ($permisoExtra->tolerancia_ilimitada) {
            return null;
        }

        if ($permisoExtra->hora_limite) {
            $horaLimite = Carbon::parse($now->toDateString().' '.$permisoExtra->hora_limite->format('H:i:s'));
            $minutosDesdeProgramada = $horaProgramada->diffInMinutes($horaLimite, false);

            if ($minutosDesdeProgramada > 0) {
                return $minutosDesdeProgramada;
            }
        }

        return $permisoExtra->tolerancia_minutos ?? self::TOLERANCIA_ENTRADA_MINUTOS;
    }

    /**
     * $permiso->hora_inicio / hora_fin pueden llegar como string "HH:MM:SS" o como Carbon
     * (si el modelo los castea a datetime). Esto normaliza a solo la hora, evitando el
     * "Double date specification" al concatenar con otra fecha.
     */
    private function soloHora($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->format('H:i:s');
        }

        // Por si ya viene con fecha pegada como string, ej "2026-07-10 17:54:05"
        if (is_string($valor) && str_contains($valor, ' ')) {
            return Carbon::parse($valor)->format('H:i:s');
        }

        return (string) $valor;
    }

    // firma nueva: agregar $puedeSalirLibre
    private function calcularPuntualidadSalida(Carbon $now, ?array $horariosHoy, ?ChecadorPermiso $permiso, UserFirebirdIdentity $identity, string $hoy, ?int $registroId, bool $puedeSalirLibre = false): array
    {
        $base = ['hora_programada' => null, 'minutos_retardo' => 0, 'es_retardo' => false, 'minutos_anticipacion' => 0, 'horas_extra' => 0];

        if ($permiso && $permiso->hora_inicio) {
            $base['hora_programada'] = $this->soloHora($permiso->hora_inicio);

            return $base;
        }

        if (! $horariosHoy || empty($horariosHoy['hora_salida'])) {
            return $base;
        }

        $horaSalidaProgramada = Carbon::parse($now->toDateString().' '.$horariosHoy['hora_salida']);
        if ($horariosHoy['sale_dia_siguiente'] ?? false) {
            $horaSalidaProgramada->addDay();
        }

        $minutosHastaSalida = $now->diffInMinutes($horaSalidaProgramada, false);
        $base['hora_programada'] = $horariosHoy['hora_salida'];

        if ($minutosHastaSalida > 0 && ! $puedeSalirLibre) {
            $base['minutos_anticipacion'] = max(0, $minutosHastaSalida - self::TOLERANCIA_SALIDA_MINUTOS);
        } elseif ($minutosHastaSalida < 0) {
            $minutosExcedente = (int) round(abs($minutosHastaSalida));
            $permisoDeuda = $this->pagoTiempoService->permisoPendientePago($identity, $hoy);

            if ($permisoDeuda) {
                $minutosExcedente = $this->pagoTiempoService->abonar(
                    $permisoDeuda,
                    $identity,
                    $minutosExcedente,
                    'salida_tardia',
                    $hoy,
                    $registroId
                );
            }

            $base['horas_extra'] = round($minutosExcedente / 60, 2);
        }

        return $base;
    }

    public function historial(int $identityId, ?string $desde = null, ?string $hasta = null)
    {
        $desde ??= now()->startOfMonth()->toDateString();
        $hasta ??= now()->toDateString();

        return ChecadorRegistro::where('user_firebird_identity_id', $identityId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderByDesc('fecha_hora')
            ->paginate(50);
    }

    private function resolverTurnoPorDefecto(UserFirebirdIdentity $identity): ?Turno
    {
        $empresa = $identity->firebird_empresa;
        if (! $empresa) {
            return null;
        }

        $deptoNombre = $this->obtenerDepartamentoNombreNoi($identity);
        if (! $deptoNombre) {
            return null;
        }

        $claveTurno = match (true) {
            Str::contains(Str::upper($deptoNombre), 'ADMINISTRATIVO') => 'ADM10',
            default => null,
        };

        if (! $claveTurno) {
            return null;
        }

        return Turno::where('firebird_empresa', $empresa)->where('clave', $claveTurno)->first();
    }

    private function obtenerDepartamentoNombreNoi(UserFirebirdIdentity $identity): ?string
    {
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;
        if (! $tbClave || ! $empresa) {
            return null;
        }

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tb = $firebirdNoi->getOperationalTable('TB')->keyBy(fn ($r) => trim((string) $r->CLAVE));
            $tbRow = $tb[trim((string) $tbClave)] ?? null;
            $deptoClave = $tbRow && isset($tbRow->DEPTO) ? trim((string) $tbRow->DEPTO) : null;
            if (! $deptoClave) {
                return null;
            }

            $deptos = $firebirdNoi->getMasterTable('DEPTOS')->keyBy(fn ($r) => trim((string) $r->CLAVE));
            $deptoRow = $deptos[$deptoClave] ?? null;

            return $deptoRow && isset($deptoRow->NOMBRE) ? trim((string) $deptoRow->NOMBRE) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function obtenerStatusEmpleadoNoi(UserFirebirdIdentity $identity): ?string
    {
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;
        if (! $tbClave || ! $empresa) {
            return null;
        }

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tb = $firebirdNoi->getOperationalTable('TB')->keyBy(fn ($r) => trim((string) $r->CLAVE));
            $tbRow = $tb[trim((string) $tbClave)] ?? null;

            return $tbRow && isset($tbRow->STATUS) ? trim((string) $tbRow->STATUS) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function obtenerDatosEmpleadoNoi(UserFirebirdIdentity $identity): array
    {
        $tbRow = null;
        $deptoRow = null;
        $puestoRow = null;
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;

        if (! $tbClave || ! $empresa) {
            return compact('tbRow', 'deptoRow', 'puestoRow');
        }

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tb = $firebirdNoi->getOperationalTable('TB')->keyBy(fn ($r) => trim((string) $r->CLAVE));
            $tbRow = $tb[trim((string) $tbClave)] ?? null;

            if ($tbRow) {
                $deptoClave = trim((string) ($tbRow->DEPTO ?? ''));
                $puestoClave = trim((string) ($tbRow->PUESTO ?? ''));

                if ($deptoClave !== '') {
                    $deptos = $firebirdNoi->getMasterTable('DEPTOS')->keyBy(fn ($r) => trim((string) $r->CLAVE));
                    $deptoRow = $deptos[$deptoClave] ?? null;
                }
                if ($puestoClave !== '') {
                    $puestos = $firebirdNoi->getMasterTable('PUESTOS')->keyBy(fn ($r) => trim((string) $r->CLAVE));
                    $puestoRow = $puestos[$puestoClave] ?? null;
                }
            }
        } catch (Throwable $e) {
            // sin datos de NOI, se regresa lo que se tenga
        }

        return compact('tbRow', 'deptoRow', 'puestoRow');
    }

    private function calcularHorasJornada(Carbon $now, Carbon $horaEntradaReal, int $identityId, string $hoy, ?array $horariosHoy): array
    {
        $segundosTrabajados = $now->getTimestamp() - $horaEntradaReal->getTimestamp();
        $segundosComidaEstandar = 0;

        $permisoComidaIds = ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->whereDate('fecha_inicio', $hoy)
            ->whereDate('fecha_fin', $hoy)
            ->whereHas('catalogo', fn ($q) => $q->where('clave', 'COMIDA'))
            ->pluck('id')
            ->toArray();

        $tuvoPermisoComida = ChecadorRegistro::where('user_firebird_identity_id', $identityId)
            ->where('fecha', $hoy)
            ->whereIn('checador_permiso_id', $permisoComidaIds)
            ->exists();

        if ($tuvoPermisoComida) {
            $catalogoComida = ChecadorCatalogoPermiso::where('clave', 'COMIDA')->first();
            $minutosComida = $catalogoComida->duracion_default_minutos ?? 60;
            $segundosComidaEstandar = $minutosComida * 60;
        } elseif (! empty($horariosHoy['hora_inicio_comida']) && ! empty($horariosHoy['hora_fin_comida'])) {
            $inicioComida = Carbon::parse($horaEntradaReal->toDateString().' '.$horariosHoy['hora_inicio_comida']);
            $finComida = Carbon::parse($horaEntradaReal->toDateString().' '.$horariosHoy['hora_fin_comida']);
            if ($finComida->lessThan($inicioComida)) {
                $finComida->addDay();
            }
            $segundosComidaEstandar = $finComida->getTimestamp() - $inicioComida->getTimestamp();
        }

        $horasTrabajadas = round(max(0, $segundosTrabajados - $segundosComidaEstandar) / 3600, 2);

        $horasEsperadas = null;
        if (! empty($horariosHoy['hora_entrada']) && ! empty($horariosHoy['hora_salida'])) {
            $entradaProg = Carbon::parse($horaEntradaReal->toDateString().' '.$horariosHoy['hora_entrada']);
            $salidaProg = Carbon::parse($horaEntradaReal->toDateString().' '.$horariosHoy['hora_salida']);
            if ($horariosHoy['entra_dia_anterior'] ?? false) {
                $entradaProg->subDay();
            }
            if ($horariosHoy['sale_dia_siguiente'] ?? false) {
                $salidaProg->addDay();
            }
            $segundosJornada = $salidaProg->getTimestamp() - $entradaProg->getTimestamp();
            $horasEsperadas = round(max(0, $segundosJornada - $segundosComidaEstandar) / 3600, 2);
        }

        return ['horas_trabajadas' => $horasTrabajadas, 'horas_esperadas' => $horasEsperadas];
    }

    private function obtenerPuestoAreaLocal(UserFirebirdIdentity $identity): ?\App\Models\UserPuesto
    {
        if ($identity->relationLoaded('puestoActivo')) {
            return $identity->puestoActivo;
        }

        return $identity->puestoActivo()->with(['puesto', 'area', 'jefe.firebirdUser'])->first();
    }

    /**
     * Bloquea la checada si hay un permiso pendiente (que NO sea de comida) cuyo jefe
     * todavía no lo autoriza y ya empezó su ventana de horario.
     */
    private function verificarPermisoNoComidaPendiente(UserFirebirdIdentity $identity, Carbon $now, string $hoy): void
    {
        $permiso = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->whereIn('estado', ['pendiente', 'solicitado'])
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->whereDoesntHave('catalogo', fn ($q) => $q->where('clave', 'COMIDA'))
            ->where(function ($q) use ($now) {
                $q->whereNull('hora_inicio')
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('hora_inicio', '<=', $now->toTimeString())
                            ->where('hora_fin', '>=', $now->toTimeString());
                    });
            })
            ->orderByDesc('created_at')
            ->first();

        if (! $permiso || $permiso->estado_jefe === 'aprobado') {
            return;
        }

        throw new RuntimeException(
            'Tu permiso todavía no puede usarse: falta la aprobación de tu jefe directo.',
            403
        );
    }

    private function permisoBloqueadoPorCierreDeTurno(ChecadorPermiso $permiso, Carbon $now, ?array $horariosHoy): bool
    {
        // La comida es parte normal de la jornada; no debe bloquearse por
        // estar cerca de la hora de salida, sin importar qué tan tarde
        // haya entrado el empleado.
        if ($permiso->catalogo && $permiso->catalogo->clave === 'COMIDA') {
            return false;
        }

        if (empty($horariosHoy['hora_salida'])) {
            return false;
        }

        $horaSalida = Carbon::parse($now->toDateString().' '.$horariosHoy['hora_salida']);
        if ($horariosHoy['sale_dia_siguiente'] ?? false) {
            $horaSalida->addDay();
        }

        $inicioBloqueo = $horaSalida->copy()->subMinutes(self::VENTANA_BLOQUEO_PERMISO_MINUTOS);

        if ($now->lt($inicioBloqueo)) {
            return false;
        }

        $yaSeHabiaUsado = ChecadorRegistro::where('checador_permiso_id', $permiso->id)->exists();

        return ! $yaSeHabiaUsado;
    }
}
