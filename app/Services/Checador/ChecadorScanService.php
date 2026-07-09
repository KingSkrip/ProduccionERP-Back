<?php

namespace App\Services\Checador;

use App\Models\ChecadorAccessQrCode;
use App\Models\ChecadorEntrada;
use App\Models\ChecadorPermiso;
use App\Models\ChecadorRegistro;
use App\Models\ChecadorSalida;
use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Turno;
use App\Services\FirebirdEmpresaManualService;
use Illuminate\Support\Str;

class ChecadorScanService
{
    /**
     * Minutos de tolerancia para marcar retardo en una ENTRADA normal
     * (sin permiso). Los permisos NO tienen tolerancia: se comparan
     * exactos contra su propia ventana de horario.
     */
    private const TOLERANCIA_ENTRADA_MINUTOS = 15;
    private const TOLERANCIA_SALIDA_MINUTOS = 30;

    /**
     * Obtiene el QR activo de una identidad (sin crearlo).
     */
    public function obtenerActivo(int $identityId): ?ChecadorAccessQrCode
    {
        return ChecadorAccessQrCode::where('user_firebird_identity_id', $identityId)
            ->where('activo', true)
            ->first();
    }

    /**
     * Genera (o reutiliza) el QR fijo de una identidad.
     * El token NUNCA cambia mientras el QR esté activo.
     */
    public function generar(int $identityId): ChecadorAccessQrCode
    {
        $identity = UserFirebirdIdentity::with('firebirdUser')->findOrFail($identityId);

        $payloadInicial = [
            'nombre' => $identity->firebirdUser->NOMBRE ?? null,
            // 🔜 aquí se agregan más campos con el tiempo sin tocar el token
        ];

        $qr = ChecadorAccessQrCode::obtenerOCrearParaIdentity(
            $identityId,
            $payloadInicial,
            $identity->firebird_empresa
        );

        Log::info('🎫 QR_GENERADO_O_REUTILIZADO', [
            'identity_id' => $identityId,
            'qr_id' => $qr->id,
            'token' => $qr->token,
            'creado' => $qr->wasRecentlyCreated,
        ]);

        return $qr;
    }

    /**
     * Revoca el QR actual (ej. usuario perdió su gafete/credencial).
     */
    public function revocar(int $identityId): ?ChecadorAccessQrCode
    {
        $qr = $this->obtenerActivo($identityId);

        if (!$qr) {
            return null;
        }

        $qr->update(['activo' => false]);

        Log::warning('🚫 QR_REVOCADO', ['identity_id' => $identityId, 'qr_id' => $qr->id]);

        return $qr;
    }

    /**
     * Registra una checada (entrada/salida) a partir de un token de QR.
     *
     * @throws \RuntimeException si el token es inválido
     */
    public function registrarChecada(string $token, array $meta = []): array
    {
        $qr = ChecadorAccessQrCode::where('token', $token)
            ->where('activo', true)
            ->with(['identity.turnoActivo.turno.turnoDias'])
            ->first();

        if (!$qr) {
            throw new \RuntimeException('QR inválido o inactivo', 404);
        }

        $identity = $qr->identity;

        if (!$identity) {
            throw new \RuntimeException('Identidad asociada al QR no encontrada', 404);
        }

        $resultado = $this->procesarChecada($identity, $qr->firebird_empresa, 'qr', $meta);

        $qr->update(['ultima_lectura' => Carbon::now()]);

        $resultado['usuario_nombre'] = $qr->payload['nombre'] ?? $resultado['usuario_nombre'];

        return $resultado;
    }

    /**
     * Registra una checada MANUAL (sin QR), para cuando el empleado no
     * tiene QR generado o el lector falla y un admin captura a mano.
     *
     * @throws \RuntimeException si la identidad no existe
     */
    public function registrarChecadaManual(int $identityId, array $meta = []): array
    {
        $identity = UserFirebirdIdentity::with(['firebirdUser', 'turnoActivo.turno.turnoDias'])
            ->find($identityId);

        if (!$identity) {
            throw new \RuntimeException('Identidad no encontrada', 404);
        }

        $resultado = $this->procesarChecada($identity, $identity->firebird_empresa, 'manual', $meta);

        $resultado['usuario_nombre'] = $identity->firebirdUser->NOMBRE ?? null;

        return $resultado;
    }

    /**
     * Lógica compartida de registro de checada (QR o manual): decide
     * tipo (entrada/salida), calcula puntualidad y persiste todo en
     * una transacción.
     *
     * @throws \RuntimeException si algo falla al guardar
     */
    private function procesarChecada(UserFirebirdIdentity $identity, ?string $firebirdEmpresa, string $metodo, array $meta): array
    {
        $status = $this->obtenerStatusEmpleadoNoi($identity);

        if ($status !== null && $status !== 'A') {
            Log::warning('🚫 CHECADA_DENEGADA_STATUS_INVALIDO', [
                'identity_id' => $identity->id,
                'status' => $status,
                'metodo' => $metodo,
            ]);

            throw new \RuntimeException('Acceso denegado: el empleado no se encuentra activo', 403);
        }

        $now = Carbon::now();
        $hoy = $now->toDateString();

        // 🔒 NUEVO: si hay un permiso que cubre este momento pero todavía le
        // falta alguno de los dos carriles de aprobación, se bloquea la
        // checada con un mensaje explícito de qué falta. Solo con AMBAS
        // aprobaciones puede usarse el permiso para entrar/salir.
        $this->verificarPermisoPendiente($identity, $now, $hoy);

        $ultimoRegistro = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->orderByDesc('fecha_hora')
            ->first();
        $tipo = (!$ultimoRegistro || $ultimoRegistro->tipo === 'salida') ? 'entrada' : 'salida';

        // Solo un permiso APROBADO (ambos carriles) puede quitar la
        // tolerancia normal y regir su propia ventana de horario.
        $permisoActivo = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->where(function ($q) use ($now) {
                $q->whereNull('hora_inicio')
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('hora_inicio', '<=', $now->toTimeString())
                            ->where('hora_fin', '>=', $now->toTimeString());
                    });
            })
            ->orderByRaw('hora_inicio IS NULL')
            ->first();

        // 🔒 CANDADO: sin permiso vigente, solo se permite 1 entrada y 1
        // salida NORMALES por día. Los movimientos hechos bajo un permiso
        // (checador_permiso_id != null) no cuentan para este límite.
        if (!$permisoActivo) {
            $registrosNormalesHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->whereNull('checador_permiso_id')
                ->get();

            $entradasNormales = $registrosNormalesHoy->where('tipo', 'entrada')->count();
            $salidasNormales = $registrosNormalesHoy->where('tipo', 'salida')->count();

            if ($entradasNormales >= 1 && $salidasNormales >= 1) {
                Log::warning('🚫 CHECADA_DENEGADA_LIMITE_DIARIO', [
                    'identity_id' => $identity->id,
                    'metodo' => $metodo,
                ]);

                throw new \RuntimeException(
                    'Ya registraste tu entrada y tu salida de hoy. Si necesitas volver a checar, se requiere un permiso aprobado.',
                    409
                );
            }
        }

        $turnoActivo = $identity->turnoActivo;
        $turnoId = $turnoActivo->turno_id ?? null;
        $horariosHoy = $turnoActivo?->getHorariosHoy();

        // 🧭 Si NO hay turno_id resuelto (sin importar si $turnoActivo viene
        // null, vacío, o sin horario), resolvemos uno por defecto según su
        // departamento NOI + empresa (por ahora solo Administrativo).
        if (!$turnoId) {
            $turnoDefault = $this->resolverTurnoPorDefecto($identity);

            if ($turnoDefault) {
                $turnoId = $turnoDefault->id;
                $horariosHoy = [
                    'hora_entrada'        => $turnoDefault->hora_entrada,
                    'hora_salida'         => $turnoDefault->hora_salida,
                    'hora_inicio_comida'  => $turnoDefault->hora_inicio_comida,
                    'hora_fin_comida'     => $turnoDefault->hora_fin_comida,
                    'entra_dia_anterior'  => (bool) $turnoDefault->entra_dia_anterior,
                    'sale_dia_siguiente'  => (bool) $turnoDefault->sale_dia_siguiente,
                ];

                Log::info('🧭 TURNO_DEFAULT_APLICADO', [
                    'identity_id' => $identity->id,
                    'turno_id' => $turnoDefault->id,
                    'clave' => $turnoDefault->clave,
                    'empresa' => $turnoDefault->firebird_empresa,
                    'tenia_turnoActivo_pero_sin_turno_id' => (bool) $turnoActivo,
                ]);
            }
        }

        $puntualidad = $tipo === 'entrada'
            ? $this->calcularPuntualidadEntrada($now, $horariosHoy, $permisoActivo)
            : $this->calcularPuntualidadSalida($now, $horariosHoy, $permisoActivo);

        $jornada = null;

        if ($tipo === 'salida') {
            $ultimaEntradaHoy = ChecadorEntrada::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->orderByDesc('hora_entrada')
                ->first();

            if ($ultimaEntradaHoy) {
                $horaEntradaReal = Carbon::parse($hoy . ' ' . $ultimaEntradaHoy->hora_entrada);
                $permisoComida = $this->obtenerPermisoComida($identity->id, $hoy, $horariosHoy);
                $jornada = $this->calcularHorasJornada($now, $horaEntradaReal, $horariosHoy, $permisoComida);
            }
        }

        $observaciones = $permisoActivo
            ? "Dentro de permiso #{$permisoActivo->id} ({$permisoActivo->motivo})"
            : null;

        if ($metodo === 'manual' && !empty($meta['observaciones_extra'])) {
            $observaciones = trim(($observaciones ? $observaciones . ' | ' : '') . $meta['observaciones_extra']);
        }


        DB::beginTransaction();

        try {
            $registro = ChecadorRegistro::create([
                'user_firebird_identity_id' => $identity->id,
                'firebird_empresa' => $firebirdEmpresa,
                'turno_id' => $turnoId,
                'checador_permiso_id' => $permisoActivo->id ?? null,
                'tipo' => $tipo,
                'fecha' => $hoy,
                'hora' => $now->toTimeString(),
                'fecha_hora' => $now,
                'metodo' => $metodo,
                'ip_address' => $meta['ip'] ?? null,
                'dispositivo' => substr((string) ($meta['user_agent'] ?? ''), 0, 250),
                'valido' => true,
                'observaciones' => $observaciones,
            ]);

            if ($tipo === 'entrada') {
                ChecadorEntrada::create([
                    'checador_registro_id' => $registro->id,
                    'user_firebird_identity_id' => $identity->id,
                    'firebird_empresa' => $firebirdEmpresa,
                    'turno_id' => $turnoId,
                    'fecha' => $hoy,
                    'hora_entrada' => $now->toTimeString(),
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
                    'hora_salida' => $now->toTimeString(),
                    'hora_programada' => $puntualidad['hora_programada'],
                    'minutos_anticipacion' => $puntualidad['minutos_anticipacion'],
                    'horas_extra' => $puntualidad['horas_extra'],
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('💥 ERROR_REGISTRAR_CHECADA', [
                'identity_id' => $identity->id,
                'metodo' => $metodo,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Error al registrar checada', 500);
        }

        Log::info('✅ CHECADA_REGISTRADA', [
            'identity_id' => $identity->id,
            'tipo' => $tipo,
            'metodo' => $metodo,
            'registro_id' => $registro->id,
            'permiso_id' => $permisoActivo->id ?? null,
            'puntualidad' => $puntualidad,
        ]);

        $datosEmpleado = $this->obtenerDatosEmpleadoNoi($identity);
        $userPuesto = $this->obtenerPuestoAreaLocal($identity);

        return [
            'registro' => $registro,
            'tipo' => $tipo,
            'usuario_nombre' => $identity->firebirdUser->NOMBRE ?? null,
            'usuario_photo' => $identity->firebirdUser->PHOTO ?? null,
            'TB' => $datosEmpleado['tbRow'],
            'DEPTO_NOI' => $datosEmpleado['deptoRow'],
            'PUESTO_NOI' => $datosEmpleado['puestoRow'],
            'USER_PUESTO' => $userPuesto, // 👈 nuevo: puesto/área/jefe propios (MySQL)

            'permiso' => $permisoActivo,
            'puntualidad' => $puntualidad,
            'jornada' => $jornada,
        ];
    }

    /**
     * Calcula minutos_retardo / es_retardo / hora_programada para una ENTRADA.
     *
     * - Con permiso aprobado vigente (que cubre la hora actual): se compara
     *   contra la hora_fin del permiso, SIN tolerancia. Cualquier minuto
     *   después de esa hora ya cuenta como retardo.
     * - Sin permiso: se compara contra el horario del turno de hoy,
     *   aplicando los 15 minutos de tolerancia antes de marcar retardo.
     */
    private function calcularPuntualidadEntrada(Carbon $now, ?array $horariosHoy, ?ChecadorPermiso $permiso): array
    {
        $base = [
            'hora_programada' => null,
            'minutos_retardo' => 0,
            'es_retardo' => false,
            'minutos_anticipacion' => 0,
            'horas_extra' => 0,
        ];

        // Caso 1: permiso vigente que ampara la entrada (ej. permiso para llegar tarde).
        if ($permiso && $permiso->hora_fin) {
            $horaLimite = Carbon::parse($permiso->fecha_inicio->toDateString() . ' ' . $permiso->hora_fin);
            $diffSegundos = $now->getTimestamp() - $horaLimite->getTimestamp();

            $base['hora_programada'] = $permiso->hora_fin;
            $base['minutos_retardo'] = $diffSegundos > 0 ? (int) floor($diffSegundos / 60) : 0;
            $base['es_retardo'] = $base['minutos_retardo'] > 0; // sin tolerancia en permisos

            return $base;
        }

        // Caso 2: sin permiso, se usa el horario normal del turno.
        if (!$horariosHoy || empty($horariosHoy['hora_entrada'])) {
            return $base;
        }

        $horaProgramada = Carbon::parse($now->toDateString() . ' ' . $horariosHoy['hora_entrada']);

        // Turno que arranca la jornada desde el día anterior (turno nocturno).
        if ($horariosHoy['entra_dia_anterior'] ?? false) {
            $horaProgramada->subDay();
        }

        $diffSegundos = $now->getTimestamp() - $horaProgramada->getTimestamp();
        $minutosTarde = $diffSegundos > 0 ? (int) floor($diffSegundos / 60) : 0;
        $minutosRetardo = max(0, $minutosTarde - self::TOLERANCIA_ENTRADA_MINUTOS);

        $base['hora_programada'] = $horariosHoy['hora_entrada'];
        $base['minutos_retardo'] = $minutosRetardo;
        $base['es_retardo'] = $minutosRetardo > 0;

        return $base;
    }

    /**
     * Calcula minutos_anticipacion / horas_extra / hora_programada para una SALIDA.
     *
     * - Con permiso aprobado vigente (que cubre la salida, ej. permiso para
     *   retirarse antes): la salida queda amparada por completo, no se
     *   penaliza como anticipación ni se otorgan horas extra. El permiso ya
     *   define su propia ventana, sin tolerancia adicional.
     * - Sin permiso: se compara contra el horario del turno; salir antes
     *   genera minutos_anticipacion, salir después genera horas_extra.
     */
    private function calcularPuntualidadSalida(Carbon $now, ?array $horariosHoy, ?ChecadorPermiso $permiso): array
    {
        $base = [
            'hora_programada' => null,
            'minutos_retardo' => 0,
            'es_retardo' => false,
            'minutos_anticipacion' => 0,
            'horas_extra' => 0,
        ];

        if ($permiso && $permiso->hora_inicio) {
            $base['hora_programada'] = $permiso->hora_inicio;
            return $base;
        }

        if (!$horariosHoy || empty($horariosHoy['hora_salida'])) {
            return $base;
        }

        $horaProgramada = Carbon::parse($now->toDateString() . ' ' . $horariosHoy['hora_salida']);

        if ($horariosHoy['sale_dia_siguiente'] ?? false) {
            $horaProgramada->addDay();
        }

        $diffSegundos = $now->getTimestamp() - $horaProgramada->getTimestamp();
        $base['hora_programada'] = $horariosHoy['hora_salida'];

        if ($diffSegundos < 0) {
            // Salió antes de su hora programada.
            $minutosAntes = (int) floor(abs($diffSegundos) / 60);

            // 🎁 Tolerancia: si salió dentro de los 30 min previos a su
            // hora programada, se le regala esa diferencia (cuenta como
            // jornada completa, no se marca anticipación).
            $base['minutos_anticipacion'] = max(0, $minutosAntes - self::TOLERANCIA_SALIDA_MINUTOS);
        } elseif ($diffSegundos > 0) {
            $base['horas_extra'] = round($diffSegundos / 3600, 2);
        }

        return $base;
    }

    /**
     * Historial paginado de registros de una identidad.
     */
    public function historial(int $identityId, ?string $desde = null, ?string $hasta = null)
    {
        $desde ??= now()->startOfMonth()->toDateString();
        $hasta ??= now()->toDateString();

        return ChecadorRegistro::where('user_firebird_identity_id', $identityId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderByDesc('fecha_hora')
            ->paginate(50);
    }

    /**
     * Resuelve un turno "por defecto" cuando el empleado no tiene
     * turno asignado en user_turnos, según su departamento NOI y
     * su empresa. Por ahora solo cubre Administrativo -> ADM10.
     */
    private function resolverTurnoPorDefecto(UserFirebirdIdentity $identity): ?Turno
    {
        $empresa = $identity->firebird_empresa;

        if (!$empresa) {
            return null;
        }

        $deptoNombre = $this->obtenerDepartamentoNombreNoi($identity);

        if (!$deptoNombre) {
            return null;
        }

        // 🔜 aquí se van a ir agregando más mapeos depto -> clave de turno
        $claveTurno = match (true) {
            Str::contains(Str::upper($deptoNombre), 'ADMINISTRATIVO') => 'ADM10',
            default => null,
        };

        if (!$claveTurno) {
            Log::info('🧭 TURNO_DEFAULT_SIN_MAPEO', [
                'identity_id' => $identity->id,
                'depto' => $deptoNombre,
            ]);
            return null;
        }

        $turno = Turno::where('firebird_empresa', $empresa)
            ->where('clave', $claveTurno)
            ->first();

        if (!$turno) {
            Log::warning('⚠️ TURNO_DEFAULT_NO_ENCONTRADO', [
                'identity_id' => $identity->id,
                'empresa' => $empresa,
                'clave' => $claveTurno,
            ]);
        }

        return $turno;
    }

    /**
     * Consulta Firebird (TB -> DEPTOS{empresa}) para obtener el
     * nombre del departamento del empleado.
     */
    private function obtenerDepartamentoNombreNoi(UserFirebirdIdentity $identity): ?string
    {
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;

        if (!$tbClave || !$empresa) {
            return null;
        }

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');

            $tb = $firebirdNoi->getOperationalTable('TB')
                ->keyBy(fn($row) => trim((string)$row->CLAVE));
            $tbRow = $tb[trim((string)$tbClave)] ?? null;

            $deptoClave = $tbRow && isset($tbRow->DEPTO) ? trim((string)$tbRow->DEPTO) : null;

            if (!$deptoClave) {
                return null;
            }

            $deptos = $firebirdNoi->getMasterTable('DEPTOS')
                ->keyBy(fn($row) => trim((string)$row->CLAVE));

            $deptoRow = $deptos[$deptoClave] ?? null;

            return $deptoRow && isset($deptoRow->NOMBRE) ? trim((string)$deptoRow->NOMBRE) : null;
        } catch (\Throwable $e) {
            Log::error('⚠️ ERROR_OBTENER_DEPTO_NOI_SCAN', [
                'identity_id' => $identity->id,
                'tb_clave' => $tbClave,
                'empresa' => $empresa,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Consulta Firebird (TB) para obtener el STATUS actual del empleado.
     * Regresa null si no se pudo determinar (en cuyo caso NO se bloquea,
     * para no tumbar el checador por un error de conexión a Firebird).
     */
    private function obtenerStatusEmpleadoNoi(UserFirebirdIdentity $identity): ?string
    {
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;

        if (!$tbClave || !$empresa) {
            return null;
        }

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');

            $tb = $firebirdNoi->getOperationalTable('TB')
                ->keyBy(fn($row) => trim((string)$row->CLAVE));
            $tbRow = $tb[trim((string)$tbClave)] ?? null;

            return $tbRow && isset($tbRow->STATUS) ? trim((string)$tbRow->STATUS) : null;
        } catch (\Throwable $e) {
            Log::error('⚠️ ERROR_OBTENER_STATUS_NOI_SCAN', [
                'identity_id' => $identity->id,
                'tb_clave' => $tbClave,
                'empresa' => $empresa,
                'error' => $e->getMessage(),
            ]);
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

        if (!$tbClave || !$empresa) {
            return compact('tbRow', 'deptoRow', 'puestoRow');
        }

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');

            $tb = $firebirdNoi->getOperationalTable('TB')
                ->keyBy(fn($row) => trim((string) $row->CLAVE));

            $tbRow = $tb[trim((string) $tbClave)] ?? null;

            if ($tbRow) {

                $deptoClave = trim((string) ($tbRow->DEPTO ?? ''));
                $puestoClave = trim((string) ($tbRow->PUESTO ?? ''));

                if ($deptoClave !== '') {
                    $deptos = $firebirdNoi->getMasterTable('DEPTOS')
                        ->keyBy(fn($row) => trim((string) $row->CLAVE));

                    $deptoRow = $deptos[$deptoClave] ?? null;
                }

                if ($puestoClave !== '') {
                    $puestos = $firebirdNoi->getMasterTable('PUESTOS')
                        ->keyBy(fn($row) => trim((string) $row->CLAVE));

                    $puestoRow = $puestos[$puestoClave] ?? null;
                }
            }
        } catch (\Throwable $e) {
            Log::error('ERROR_OBTENIENDO_DATOS_NOI_CHECADOR', [
                'identity_id' => $identity->id,
                'error' => $e->getMessage(),
            ]);
        }

        return compact('tbRow', 'deptoRow', 'puestoRow');
    }


    private function calcularHorasJornada(Carbon $now, Carbon $horaEntradaReal, ?array $horariosHoy, ?ChecadorPermiso $permisoComida = null): array
    {
        // Horas trabajadas (bruto: desde que entró hasta que sale ahora)
        $segundosTrabajados = $now->getTimestamp() - $horaEntradaReal->getTimestamp();

        $segundosComida = 0;
        $segundosComidaEstandar = 0;

        if (!empty($horariosHoy['hora_inicio_comida']) && !empty($horariosHoy['hora_fin_comida'])) {
            $inicioComida = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_inicio_comida']);
            $finComida = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_fin_comida']);

            if ($finComida->lessThan($inicioComida)) {
                $finComida->addDay();
            }

            $segundosComidaEstandar = $finComida->getTimestamp() - $inicioComida->getTimestamp();
            $segundosComida = $segundosComidaEstandar;

            // 🍽️ Si hay un permiso aprobado que cubre/extiende la comida, ya
            // no se descuenta la comida estándar completa: solo se descuenta
            // lo que el permiso se pasó de esa ventana normal.
            // Ej: comida normal 1h, permiso de 1h06 -> solo se descuentan
            // esos 6 min extra (lo demás queda regalado por el permiso).
            if ($permisoComida && $permisoComida->hora_inicio && $permisoComida->hora_fin) {
                $inicioPermiso = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $permisoComida->hora_inicio);
                $finPermiso = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $permisoComida->hora_fin);

                if ($finPermiso->lessThan($inicioPermiso)) {
                    $finPermiso->addDay();
                }

                $segundosPermiso = max(0, $finPermiso->getTimestamp() - $inicioPermiso->getTimestamp());

                // Solo se cobra (descuenta) el excedente sobre la comida estándar.
                $segundosComida = max(0, $segundosPermiso - $segundosComidaEstandar);
            }
        }

        $horasTrabajadas = round(max(0, $segundosTrabajados - $segundosComida) / 3600, 2);

        // Horas esperadas según el turno (siempre resta la comida ESTÁNDAR,
        // el permiso no cambia cuánto "debía" trabajar, solo cuánto sí trabajó).
        $horasEsperadas = null;
        if (!empty($horariosHoy['hora_entrada']) && !empty($horariosHoy['hora_salida'])) {
            $entradaProg = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_entrada']);
            $salidaProg = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_salida']);

            if ($horariosHoy['entra_dia_anterior'] ?? false) {
                $entradaProg->subDay();
            }
            if ($horariosHoy['sale_dia_siguiente'] ?? false) {
                $salidaProg->addDay();
            }

            $segundosJornada = $salidaProg->getTimestamp() - $entradaProg->getTimestamp();
            $horasEsperadas = round(max(0, $segundosJornada - $segundosComidaEstandar) / 3600, 2);
        }

        return [
            'horas_trabajadas' => $horasTrabajadas,
            'horas_esperadas' => $horasEsperadas,
        ];
    }



    /**
     * Busca un permiso aprobado que se traslape con la ventana de comida
     * programada del turno, para saber si hay que exentar (o solo cobrar
     * el excedente de) el tiempo de comida en el cálculo de jornada.
     */
    private function obtenerPermisoComida(int $identityId, string $fecha, ?array $horariosHoy): ?ChecadorPermiso
    {
        if (!$horariosHoy || empty($horariosHoy['hora_inicio_comida']) || empty($horariosHoy['hora_fin_comida'])) {
            return null;
        }

        return ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fin')
            ->where('hora_inicio', '<', $horariosHoy['hora_fin_comida'])
            ->where('hora_fin', '>', $horariosHoy['hora_inicio_comida'])
            ->orderByDesc('hora_fin')
            ->first();
    }

    /**
     * Trae el puesto/área "propios" (tabla user_puestos en MySQL),
     * no el de Firebird/NOI.
     */
    private function obtenerPuestoAreaLocal(UserFirebirdIdentity $identity): ?\App\Models\UserPuesto
    {
        // Si ya viene cargado (eager load), lo reusamos; si no, lo consultamos.
        if ($identity->relationLoaded('puestoActivo')) {
            return $identity->puestoActivo;
        }

        return $identity->puestoActivo()
            ->with(['puesto', 'area', 'jefe.firebirdUser'])
            ->first();
    }



    /**
     * Si existe un permiso (en cualquier estado) que cubre la fecha/hora
     * actual pero todavía no tiene AMBAS aprobaciones (RH + jefe), se
     * bloquea la checada con un mensaje que indica exactamente qué falta.
     *
     * No aplica a permisos ya rechazados: un permiso rechazado simplemente
     * se ignora y la checada se procesa como si no existiera (cae en las
     * reglas normales de tolerancia/candado diario).
     *
     * @throws \RuntimeException si el permiso está incompleto de aprobación
     */
    private function verificarPermisoPendiente(UserFirebirdIdentity $identity, Carbon $now, string $hoy): void
    {
        $permiso = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('estado', '!=', 'rechazado')
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->where(function ($q) use ($now) {
                $q->whereNull('hora_inicio')
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('hora_inicio', '<=', $now->toTimeString())
                            ->where('hora_fin', '>=', $now->toTimeString());
                    });
            })
            ->orderByRaw('hora_inicio IS NULL')
            ->first();

        if (!$permiso || $permiso->estado === 'aprobado') {
            return; // no hay permiso en juego, o ya está completo
        }

        $faltantes = [];

        if ($permiso->estado_rh !== 'aprobado') {
            $faltantes[] = 'RH';
        }

        if ($permiso->estado_jefe !== 'aprobado') {
            $faltantes[] = 'tu jefe directo';
        }

        $mensaje = 'Tu permiso todavía no puede usarse: falta la aprobación de ' . implode(' y ', $faltantes) . '.';

        Log::warning('🚫 CHECADA_DENEGADA_PERMISO_INCOMPLETO', [
            'identity_id' => $identity->id,
            'permiso_id' => $permiso->id,
            'estado_rh' => $permiso->estado_rh,
            'estado_jefe' => $permiso->estado_jefe,
        ]);

        throw new \RuntimeException($mensaje, 403);
    }
}