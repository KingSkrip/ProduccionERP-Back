<?php

namespace App\Services\Checador;

use App\Models\ChecadorAccessQrCode;
use App\Models\ChecadorCatalogoPermiso;
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
use RuntimeException;
use Throwable;

class ChecadorScanService
{
    private const TOLERANCIA_ENTRADA_MINUTOS = 15;
    private const TOLERANCIA_SALIDA_MINUTOS = 30;
    private const VENTANA_BLOQUEO_PERMISO_MINUTOS = 60;

    private ChecadorPermisoService $permisoService;

    public function __construct(?ChecadorPermisoService $permisoService = null)
    {
        $this->permisoService = $permisoService ?? new ChecadorPermisoService();
    }

    public function obtenerActivo(int $identityId): ?ChecadorAccessQrCode
    {
        return ChecadorAccessQrCode::where('user_firebird_identity_id', $identityId)
            ->where('activo', true)
            ->first();
    }

    public function generar(int $identityId): ChecadorAccessQrCode
    {
        $identity = UserFirebirdIdentity::with('firebirdUser')->findOrFail($identityId);

        $payloadInicial = [
            'nombre' => $identity->firebirdUser->NOMBRE ?? null,
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

    public function revocar(int $identityId): ?ChecadorAccessQrCode
    {
        $qr = $this->obtenerActivo($identityId);
        if (!$qr) return null;
        $qr->update(['activo' => false]);
        Log::warning('🚫 QR_REVOCADO', ['identity_id' => $identityId, 'qr_id' => $qr->id]);
        return $qr;
    }

    public function registrarChecada(string $token, array $meta = []): array
    {
        $qr = ChecadorAccessQrCode::where('token', $token)
            ->where('activo', true)
            ->with(['identity.turnoActivo.turno.turnoDias'])
            ->first();

        if (!$qr) throw new \RuntimeException('QR inválido o inactivo', 404);

        $identity = $qr->identity;
        if (!$identity) throw new \RuntimeException('Identidad asociada al QR no encontrada', 404);

        $resultado = $this->procesarChecada($identity, $qr->firebird_empresa, 'qr', $meta);
        $qr->update(['ultima_lectura' => Carbon::now()]);
        $resultado['usuario_nombre'] = $qr->payload['nombre'] ?? $resultado['usuario_nombre'];

        return $resultado;
    }

    public function registrarChecadaManual(int $identityId, array $meta = []): array
    {
        $identity = UserFirebirdIdentity::with(['firebirdUser', 'turnoActivo.turno.turnoDias'])
            ->find($identityId);
        if (!$identity) throw new \RuntimeException('Identidad no encontrada', 404);
        
        $resultado = $this->procesarChecada($identity, $identity->firebird_empresa, 'manual', $meta);
        $resultado['usuario_nombre'] = $identity->firebirdUser->NOMBRE ?? null;
        return $resultado;
    }

    private function procesarChecada(UserFirebirdIdentity $identity, ?string $firebirdEmpresa, string $metodo, array $meta): array
    {
        $status = $this->obtenerStatusEmpleadoNoi($identity);
        if ($status !== null && $status !== 'A') {
            throw new \RuntimeException('Acceso denegado: el empleado no se encuentra activo', 403);
        }

        $now = Carbon::now();
        $hoy = $now->toDateString();

        $this->verificarPermisoPendiente($identity, $now, $hoy);

        $turnoActivo = $identity->turnoActivo;
        $turnoId = $turnoActivo->turno_id ?? null;
        $horariosHoy = $turnoActivo?->getHorariosHoy();

        // 🚨 Si no hay horario para hoy configurado en turno_dias, forzamos la carga directa del Turno
        if (!$turnoId || !$horariosHoy) {
            $turno = $turnoId ? Turno::find($turnoId) : $this->resolverTurnoPorDefecto($identity);
            
            if ($turno) {
                $turnoId = $turno->id;
                $horariosHoy = [
                    'hora_entrada'        => $turno->hora_entrada,
                    'hora_salida'         => $turno->hora_salida,
                    'hora_inicio_comida'  => $turno->hora_inicio_comida,
                    'hora_fin_comida'     => $turno->hora_fin_comida,
                    'entra_dia_anterior'  => (bool) $turno->entra_dia_anterior,
                    'sale_dia_siguiente'  => (bool) $turno->sale_dia_siguiente,
                ];

                Log::info('🧭 TURNO_CARGADO_DESDE_DB', [
                    'identity_id' => $identity->id,
                    'turno_id' => $turno->id,
                    'hora_salida' => $turno->hora_salida,
                ]);
            } else {
                throw new \RuntimeException(
                    'No se pudo registrar la checada: el empleado no tiene turno configurado.',
                    422
                );
            }
        }

        $ultimoRegistro = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->orderByDesc('fecha_hora')
            ->first();

        // 🍽️ Autogenerar permiso de comida SOLO en la primer checada del día
        if (!$ultimoRegistro) {
            $permisoComida = $this->permisoService->crearPermisoComidaAutomaticoSiAplica($identity, $hoy, $horariosHoy);
            Log::info('🍽️ DEBUG_COMIDA_POST', [
                'identity_id' => $identity->id,
                'permiso_creado' => $permisoComida ? $permisoComida->id : 'NULL',
            ]);
        }

        // 🆕 Lógica para permisos: 1 solo uso por día. No se puede reutilizar si ya se regresó de comer.
        $permisoActivo = null;

        if ($ultimoRegistro && $ultimoRegistro->tipo === 'Inicio de permiso' && $ultimoRegistro->checador_permiso_id) {
            // Si está afuera en un permiso, dejamos que lo termine sin importar cuánto tiempo lleve
            $permisoActivo = ChecadorPermiso::with('catalogo')->find($ultimoRegistro->checador_permiso_id);
        } else {
            // Si está adentro, buscamos un permiso disponible que NO se haya usado ya hoy para regresar
            $permisosDisponibles = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
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
                ->with('catalogo')
                ->orderByRaw('hora_inicio IS NULL')
                ->get();

            foreach ($permisosDisponibles as $permiso) {
                // Verificamos si ya se usó para un 'Fin de permiso' hoy (ya regresaron de usarlo)
                $yaRegresoDeEstePermisoHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                    ->where('fecha', $hoy)
                    ->where('tipo', 'Fin de permiso')
                    ->where('checador_permiso_id', $permiso->id)
                    ->exists();

                if (!$yaRegresoDeEstePermisoHoy) {
                    $permisoActivo = $permiso;
                    break;
                }
            }
        }

        if ($permisoActivo && $this->permisoBloqueadoPorCierreDeTurno($permisoActivo, $now, $horariosHoy)) {
            $permisoActivo = null;
        }

        // 🆕 Lógica CORREGIDA para distinguir entre entrada, salida, inicio/fin permiso
        $estabaAfuera = !$ultimoRegistro || in_array($ultimoRegistro->tipo, ['salida', 'Inicio de permiso']);

        if ($estabaAfuera) {
            // Está entrando
            if ($ultimoRegistro && $ultimoRegistro->tipo === 'Inicio de permiso') {
                $tipo = 'Fin de permiso'; // Regresando de comer o de un permiso
            } else {
                $tipo = 'entrada'; // Primer entrada del día o entrada normal después de una salida
                
                // 🚨 Si es una entrada normal, NO le adjuntamos el permiso flotante.
                if ($permisoActivo && $permisoActivo->hora_inicio === null) {
                    $permisoActivo = null;
                }
            }
        } else {
            // Está saliendo
            if ($permisoActivo) {
                $tipo = 'Inicio de permiso'; // Saliendo a comer o usando un permiso activo
            } else {
                $tipo = 'salida'; // Salida final del día
            }
        }

        if (!$permisoActivo) {
            $registrosNormalesHoy = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->whereNull('checador_permiso_id')
                ->get();

            $entradasNormales = $registrosNormalesHoy->whereIn('tipo', ['entrada', 'Fin de permiso'])->count();
            $salidasNormales = $registrosNormalesHoy->whereIn('tipo', ['salida', 'Inicio de permiso'])->count();

            if ($entradasNormales >= 1 && $salidasNormales >= 1) {
                throw new \RuntimeException(
                    'Ya registraste tu entrada y tu salida de hoy. Si necesitas volver a checar, se requiere un permiso aprobado.',
                    409
                );
            }
        }

        $esEntrada = in_array($tipo, ['entrada', 'Fin de permiso']);
        
        $puntualidad = $esEntrada
            ? $this->calcularPuntualidadEntrada($now, $horariosHoy, $permisoActivo)
            : $this->calcularPuntualidadSalida($now, $horariosHoy, $permisoActivo);

        $jornada = null;
        if (!$esEntrada) {
            $ultimaEntradaHoy = ChecadorEntrada::where('user_firebird_identity_id', $identity->id)
                ->where('fecha', $hoy)
                ->orderByDesc('hora_entrada')
                ->first();

            if ($ultimaEntradaHoy) {
                $horaEntradaReal = Carbon::parse($hoy . ' ' . $ultimaEntradaHoy->hora_entrada);
                $jornada = $this->calcularHorasJornada($now, $horaEntradaReal, $identity->id, $hoy, $horariosHoy);
            }
        }

        $observaciones = $permisoActivo
            ? "Dentro de permiso #{$permisoActivo->id} ({$permisoActivo->motivo})"
            : null;

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

            if ($esEntrada) {
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
        } catch (Throwable $e) {
            DB::rollBack();
            throw new RuntimeException('Error al registrar checada', 500);
        }

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
            'USER_PUESTO' => $userPuesto,
            'permiso' => $permisoActivo,
            'puntualidad' => $puntualidad,
            'jornada' => $jornada,
        ];
    }
    
    private function calcularPuntualidadEntrada(Carbon $now, ?array $horariosHoy, ?ChecadorPermiso $permiso): array
    {
        $base = ['hora_programada' => null, 'minutos_retardo' => 0, 'es_retardo' => false, 'minutos_anticipacion' => 0, 'horas_extra' => 0];
        if ($permiso && $permiso->hora_fin) {
            $horaLimite = Carbon::parse($permiso->fecha_inicio->toDateString() . ' ' . $permiso->hora_fin);
            $diff = $now->getTimestamp() - $horaLimite->getTimestamp();
            $base['hora_programada'] = $permiso->hora_fin;
            $base['minutos_retardo'] = $diff > 0 ? (int) floor($diff / 60) : 0;
            $base['es_retardo'] = $base['minutos_retardo'] > 0;
            return $base;
        }
        if (!$horariosHoy || empty($horariosHoy['hora_entrada'])) return $base;

        $horaProgramada = Carbon::parse($now->toDateString() . ' ' . $horariosHoy['hora_entrada']);
        if ($horariosHoy['entra_dia_anterior'] ?? false) $horaProgramada->subDay();
        $diff = $now->getTimestamp() - $horaProgramada->getTimestamp();
        $minutosTarde = $diff > 0 ? (int) floor($diff / 60) : 0;
        $minutosRetardo = max(0, $minutosTarde - self::TOLERANCIA_ENTRADA_MINUTOS);

        $base['hora_programada'] = $horariosHoy['hora_entrada'];
        $base['minutos_retardo'] = $minutosRetardo;
        $base['es_retardo'] = $minutosRetardo > 0;
        return $base;
    }

    private function calcularPuntualidadSalida(Carbon $now, ?array $horariosHoy, ?ChecadorPermiso $permiso): array
    {
        $base = ['hora_programada' => null, 'minutos_retardo' => 0, 'es_retardo' => false, 'minutos_anticipacion' => 0, 'horas_extra' => 0];
        if ($permiso && $permiso->hora_inicio) {
            $base['hora_programada'] = $permiso->hora_inicio;
            return $base;
        }
        if (!$horariosHoy || empty($horariosHoy['hora_salida'])) return $base;

        $horaProgramada = Carbon::parse($now->toDateString() . ' ' . $horariosHoy['hora_salida']);
        if ($horariosHoy['sale_dia_siguiente'] ?? false) $horaProgramada->addDay();
        $diff = $now->getTimestamp() - $horaProgramada->getTimestamp();
        $base['hora_programada'] = $horariosHoy['hora_salida'];

        if ($diff < 0) {
            $minutosAntes = (int) floor(abs($diff) / 60);
            $base['minutos_anticipacion'] = max(0, $minutosAntes - self::TOLERANCIA_SALIDA_MINUTOS);
        } elseif ($diff > 0) {
            $base['horas_extra'] = round($diff / 3600, 2);
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
        if (!$empresa) return null;
        $deptoNombre = $this->obtenerDepartamentoNombreNoi($identity);
        if (!$deptoNombre) return null;

        $claveTurno = match (true) {
            Str::contains(Str::upper($deptoNombre), 'ADMINISTRATIVO') => 'ADM10',
            default => null,
        };
        if (!$claveTurno) return null;

        return Turno::where('firebird_empresa', $empresa)->where('clave', $claveTurno)->first();
    }

    private function obtenerDepartamentoNombreNoi(UserFirebirdIdentity $identity): ?string
    {
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;
        if (!$tbClave || !$empresa) return null;

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tb = $firebirdNoi->getOperationalTable('TB')->keyBy(fn($r) => trim((string)$r->CLAVE));
            $tbRow = $tb[trim((string)$tbClave)] ?? null;
            $deptoClave = $tbRow && isset($tbRow->DEPTO) ? trim((string)$tbRow->DEPTO) : null;
            if (!$deptoClave) return null;
            $deptos = $firebirdNoi->getMasterTable('DEPTOS')->keyBy(fn($r) => trim((string)$r->CLAVE));
            $deptoRow = $deptos[$deptoClave] ?? null;
            return $deptoRow && isset($deptoRow->NOMBRE) ? trim((string)$deptoRow->NOMBRE) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function obtenerStatusEmpleadoNoi(UserFirebirdIdentity $identity): ?string
    {
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;
        if (!$tbClave || !$empresa) return null;

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tb = $firebirdNoi->getOperationalTable('TB')->keyBy(fn($r) => trim((string)$r->CLAVE));
            $tbRow = $tb[trim((string)$tbClave)] ?? null;
            return $tbRow && isset($tbRow->STATUS) ? trim((string)$tbRow->STATUS) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function obtenerDatosEmpleadoNoi(UserFirebirdIdentity $identity): array
    {
        $tbRow = null; $deptoRow = null; $puestoRow = null;
        $tbClave = $identity->firebird_tb_clave;
        $empresa = $identity->firebird_empresa;
        if (!$tbClave || !$empresa) return compact('tbRow', 'deptoRow', 'puestoRow');

        try {
            $firebirdNoi = new FirebirdEmpresaManualService($empresa, 'SRVNOI');
            $tb = $firebirdNoi->getOperationalTable('TB')->keyBy(fn($r) => trim((string)$r->CLAVE));
            $tbRow = $tb[trim((string)$tbClave)] ?? null;

            if ($tbRow) {
                $deptoClave = trim((string)($tbRow->DEPTO ?? ''));
                $puestoClave = trim((string)($tbRow->PUESTO ?? ''));
                if ($deptoClave !== '') {
                    $deptos = $firebirdNoi->getMasterTable('DEPTOS')->keyBy(fn($r) => trim((string)$r->CLAVE));
                    $deptoRow = $deptos[$deptoClave] ?? null;
                }
                if ($puestoClave !== '') {
                    $puestos = $firebirdNoi->getMasterTable('PUESTOS')->keyBy(fn($r) => trim((string)$r->CLAVE));
                    $puestoRow = $puestos[$puestoClave] ?? null;
                }
            }
        } catch (\Throwable $e) {}
        return compact('tbRow', 'deptoRow', 'puestoRow');
    }

    private function calcularHorasJornada(Carbon $now, Carbon $horaEntradaReal, int $identityId, string $hoy, ?array $horariosHoy): array
    {
        $segundosTrabajados = $now->getTimestamp() - $horaEntradaReal->getTimestamp();
        $segundosComidaEstandar = 0;

        $permisoComidaIds = ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->whereDate('fecha_inicio', $hoy)
            ->whereDate('fecha_fin', $hoy)
            ->whereHas('catalogo', fn($q) => $q->where('clave', 'COMIDA'))
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
        } elseif (!empty($horariosHoy['hora_inicio_comida']) && !empty($horariosHoy['hora_fin_comida'])) {
            $inicioComida = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_inicio_comida']);
            $finComida = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_fin_comida']);
            if ($finComida->lessThan($inicioComida)) $finComida->addDay();
            $segundosComidaEstandar = $finComida->getTimestamp() - $inicioComida->getTimestamp();
        }

        $horasTrabajadas = round(max(0, $segundosTrabajados - $segundosComidaEstandar) / 3600, 2);

        $horasEsperadas = null;
        if (!empty($horariosHoy['hora_entrada']) && !empty($horariosHoy['hora_salida'])) {
            $entradaProg = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_entrada']);
            $salidaProg = Carbon::parse($horaEntradaReal->toDateString() . ' ' . $horariosHoy['hora_salida']);
            if ($horariosHoy['entra_dia_anterior'] ?? false) $entradaProg->subDay();
            if ($horariosHoy['sale_dia_siguiente'] ?? false) $salidaProg->addDay();
            $segundosJornada = $salidaProg->getTimestamp() - $entradaProg->getTimestamp();
            $horasEsperadas = round(max(0, $segundosJornada - $segundosComidaEstandar) / 3600, 2);
        }

        return ['horas_trabajadas' => $horasTrabajadas, 'horas_esperadas' => $horasEsperadas];
    }

    private function obtenerPuestoAreaLocal(UserFirebirdIdentity $identity): ?\App\Models\UserPuesto
    {
        if ($identity->relationLoaded('puestoActivo')) return $identity->puestoActivo;
        return $identity->puestoActivo()->with(['puesto', 'area', 'jefe.firebirdUser'])->first();
    }

    private function verificarPermisoPendiente(UserFirebirdIdentity $identity, Carbon $now, string $hoy): void
    {
        $permiso = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->whereIn('estado', ['pendiente', 'solicitado'])
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy)
            ->whereDoesntHave('catalogo', fn($q) => $q->where('clave', 'COMIDA'))
            ->where(function ($q) use ($now) {
                $q->whereNull('hora_inicio')
                    ->orWhere(function ($q2) use ($now) {
                        $q2->where('hora_inicio', '<=', $now->toTimeString())
                            ->where('hora_fin', '>=', $now->toTimeString());
                    });
            })
            ->orderByDesc('created_at')
            ->first();

        if (!$permiso || $permiso->estado_jefe === 'aprobado') return;

        throw new \RuntimeException('Tu permiso todavía no puede usarse: falta la aprobación de tu jefe directo.', 403);
    }

    private function permisoBloqueadoPorCierreDeTurno(ChecadorPermiso $permiso, Carbon $now, ?array $horariosHoy): bool
    {
        if (empty($horariosHoy['hora_salida'])) return false;

        $horaSalida = Carbon::parse($now->toDateString() . ' ' . $horariosHoy['hora_salida']);
        if ($horariosHoy['sale_dia_siguiente'] ?? false) $horaSalida->addDay();
        $inicioBloqueo = $horaSalida->copy()->subMinutes(self::VENTANA_BLOQUEO_PERMISO_MINUTOS);

        if ($now->lessThan($inicioBloqueo)) return false;

        $yaSeHabiaUsado = ChecadorRegistro::where('checador_permiso_id', $permiso->id)->exists();
        return !$yaSeHabiaUsado;
    }
}