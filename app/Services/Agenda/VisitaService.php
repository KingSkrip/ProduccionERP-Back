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

class VisitaService
{
    private static int $whatsappQueueIndex = 0;

    public function __construct(
        private FirebirdEmpresaManualService $firebird,
        private UserService $userService,
        private CitaNotificacionService $notif,
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

    /**
     * Citas del usuario autenticado (como anfitrión o visitante).
     */
    public function listarCitasDeIdentity(UserFirebirdIdentity $identity): \Illuminate\Support\Collection
    {
        $citas = Cita::with(['usuario', 'visitante'])
            ->where(function ($q) use ($identity) {
                $q->where('id_user', $identity->id)
                  ->orWhere('id_visitante', $identity->id);
            })
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio', 'asc')
            ->get();

        return $citas->map(function ($cita) use ($identity) {
            $cita->es_externa = $cita->id_visitante === $identity->id
                && $cita->id_user !== $identity->id;

            if ($cita->es_externa) {
                $this->adjuntarNombreOrganizador($cita);
            }

            return $cita;
        });
    }

    /**
     * Todas las citas (vista admin).
     */
    public function listarTodasLasCitas(): \Illuminate\Support\Collection
    {
        return Cita::with(['usuario', 'visitante'])
            ->orderBy('fecha', 'desc')
            ->orderBy('hora_inicio', 'asc')
            ->get()
            ->map(function ($cita) {
                $cita->es_externa = false;
                return $cita;
            });
    }

    /* ══════════════════════════════════════════════
     |  CREAR — anfitrión interno invita proveedor
     ══════════════════════════════════════════════ */

    /**
     * @return array{citasCreadas: Cita[], errores: string[]}
     */
    public function crearCitasAnfitrion(Request $request, UserFirebirdIdentity $identity): array
    {
        $idAnfitrion      = $identity->id;
        $citasCreadas     = [];
        $errores          = [];
        $nombreExterno    = trim((string) ($request->nombre_visitante_externo ?? ''));
        $tieneExterno     = strlen($nombreExterno) > 0;
        $tieneRegistrados = !empty($request->visitantes);

        $meData            = $this->userService->me($request);
        $telefonoAnfitrion = $this->obtenerTelefonoUsuario($meData['user']);
        $nombreAnfitrion   = $this->resolverNombreDeUserData($meData['user']);

        // ── Visitante EXTERNO ─────────────────────────────────────────────
        if ($tieneExterno) {
            $cita = Cita::create([
                'cita_type_id'     => 1,
                'id_user'          => $idAnfitrion,
                'id_visitante'     => null,
                'nombre_visitante' => $nombreExterno,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->motivo,
                'estado'           => $request->estado ?? 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => $request->con_vehiculo ?? false,
                'created_at'       => now(),
            ]);

            $citasCreadas[] = $cita;

            [$fecha, $horaIni, $horaFin] = $this->formatearFechaHora($cita->fecha, $cita->hora_inicio, $cita->hora_fin);
            $notasTexto = $request->notas ? "\n\n📝 Notas: {$request->notas}" : '';

            try {
                if ($telefonoAnfitrion) {
                    $msg = $this->notif->mensajeNuevaCitaPendiente($nombreExterno, $fecha, $horaIni, $horaFin, $cita, true);
                    $this->notif->enviarConJob($telefonoAnfitrion, $msg, self::$whatsappQueueIndex++);
                }
            } catch (\Throwable $e) {
                Log::error('❌ EXTERNO_WHATSAPP_ERROR', ['error' => $e->getMessage()]);
            }

            $this->notificarJefeSegPatrimonial(
                $request,
                "🔔 Nueva cita registrada:\n"
                    . "*{$nombreAnfitrion}* agendó una cita con *{$nombreExterno}* (visitante externo)\n"
                    . "el *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                    . ($request->con_vehiculo ? "\n🚗 Asistirá con vehículo." : "\n🚗 Sin vehículo.")
                    . $notasTexto
            );
        }

        // ── Visitantes REGISTRADOS ────────────────────────────────────────
        if ($tieneRegistrados) {
            foreach ($request->visitantes as $idProveedor) {
                $proveedorIdentity = UserFirebirdIdentity::find($idProveedor);

                if (!$proveedorIdentity) {
                    $errores[] = "Proveedor con id {$idProveedor} no encontrado.";
                    continue;
                }

                $cruce = $this->detectarCruceEnAgenda($proveedorIdentity->id, $request->fecha, $request->hora_inicio, $request->hora_fin);
                if ($cruce) {
                    $errores[] = $this->mensajeCruce($proveedorIdentity, $cruce);
                    continue;
                }

                $nombreProv = $proveedorIdentity->firebirdUser->NOMBRE ?? null;

                $cita = Cita::create([
                    'cita_type_id'     => 1,
                    'id_user'          => $idAnfitrion,
                    'id_visitante'     => $proveedorIdentity->id,
                    'nombre_visitante' => $nombreProv,
                    'fecha'            => $request->fecha,
                    'hora_inicio'      => $request->hora_inicio,
                    'hora_fin'         => $request->hora_fin,
                    'motivo'           => $request->motivo,
                    'estado'           => $request->estado ?? 'pendiente',
                    'notas'            => $request->notas,
                    'con_vehiculo'     => $request->con_vehiculo ?? false,
                    'created_at'       => now(),
                ]);

                $citasCreadas[]    = $cita;
                $telefonoProveedor = $this->obtenerTelefonoDeIdentity($proveedorIdentity);

                [$fecha, $horaIni, $horaFin] = $this->formatearFechaHora($cita->fecha, $cita->hora_inicio, $cita->hora_fin);
                $notasTexto = $request->notas ? "\n\n📝 Notas adicionales: {$request->notas}" : '';

                try {
                    if ($telefonoAnfitrion) {
                        $msg = $this->notif->mensajeNuevaCitaPendiente($nombreProv, $fecha, $horaIni, $horaFin, $cita, true);
                        $this->notif->enviarConJob($telefonoAnfitrion, $msg, self::$whatsappQueueIndex++);
                    }
                } catch (\Throwable $e) {
                    Log::error('❌ INVITAR_PROVEEDOR_WHATSAPP_ANFITRION_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
                }

                try {
                    if ($telefonoProveedor) {
                        $msg = $this->notif->mensajeNuevaCitaPendiente($nombreAnfitrion, $fecha, $horaIni, $horaFin, $cita, false);
                        $this->notif->enviarConJob($telefonoProveedor, $msg, self::$whatsappQueueIndex++);
                    }
                } catch (\Throwable $e) {
                    Log::error('❌ INVITAR_PROVEEDOR_WHATSAPP_PROVEEDOR_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
                }

                $this->notificarJefeSegPatrimonial(
                    $request,
                    "🔔 Nueva cita registrada:\n"
                        . "*{$nombreAnfitrion}* agendó una cita con el proveedor *{$nombreProv}*\n"
                        . "el *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->con_vehiculo ? "\n🚗 Asistirá con vehículo." : "\n🚗 Asistirá sin vehículo.")
                        . $notasTexto
                );

                Log::info('📞 TELEFONOS_STORE', [
                    'cita_id'            => $cita->id,
                    'telefono_anfitrion' => $telefonoAnfitrion,
                    'telefono_proveedor' => $telefonoProveedor,
                ]);
            }
        }

        return compact('citasCreadas', 'errores');
    }

    /* ══════════════════════════════════════════════
     |  CREAR — proveedor agenda su propia visita
     ══════════════════════════════════════════════ */

    /**
     * @return array{citasCreadas: Cita[], errores: string[]}
     */
    public function crearCitasProveedor(Request $request, UserFirebirdIdentity $identity): array
    {
        $idProveedor     = $identity->id;
        $citasCreadas    = [];
        $errores         = [];
        $nombreProveedor = $this->notif->obtenerNombrePorIdentity($identity) ?? 'Sin nombre';

        $meData            = $this->userService->me($request);
        $telefonoProveedor = $this->obtenerTelefonoUsuario($meData['user']);

        foreach ($request->visitantes as $idVisitante) {
            $visitanteIdentity = UserFirebirdIdentity::find($idVisitante);

            if (!$visitanteIdentity) {
                Log::warning('❌ VISITANTE_IDENTITY_NO_ENCONTRADO', ['id_visitante' => $idVisitante]);
                $errores[] = "Visitante con id {$idVisitante} no encontrado.";
                continue;
            }

            $cruce = $this->detectarCruceEnAgenda($visitanteIdentity->id, $request->fecha, $request->hora_inicio, $request->hora_fin);
            if ($cruce) {
                $errores[] = $this->mensajeCruce($visitanteIdentity, $cruce);
                continue;
            }

            $cita = Cita::create([
                'cita_type_id'     => 1,
                'id_user'          => $idProveedor,
                'id_visitante'     => $visitanteIdentity->id,
                'nombre_visitante' => $visitanteIdentity->firebirdUser->NOMBRE ?? null,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->motivo,
                'estado'           => 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => $request->con_vehiculo ?? false,
                'created_at'       => now(),
            ]);

            $citasCreadas[]    = $cita;
            $nombreVisitante   = $visitanteIdentity->firebirdUser->NOMBRE ?? "ID {$idVisitante}";
            $telefonoVisitante = $this->obtenerTelefonoDeIdentity($visitanteIdentity);

            [$fecha, $horaIni, $horaFin] = $this->formatearFechaHora($cita->fecha, $cita->hora_inicio, $cita->hora_fin);
            $notasTexto = $request->notas ? "\n\n📝 Notas adicionales: {$request->notas}" : '';

            try {
                if ($telefonoProveedor) {
                    $msg = $this->notif->mensajeNuevaCitaPendiente($nombreVisitante, $fecha, $horaIni, $horaFin, $cita, true);
                    $this->notif->enviarConJob($telefonoProveedor, $msg, self::$whatsappQueueIndex++);
                }
            } catch (\Throwable $e) {
                Log::error('❌ CITA_PROVEEDOR_WHATSAPP_PROVEEDOR_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
            }

            try {
                if ($telefonoVisitante) {
                    $msg = $this->notif->mensajeNuevaCitaPendiente($nombreProveedor, $fecha, $horaIni, $horaFin, $cita, false);
                    $this->notif->enviarConJob($telefonoVisitante, $msg, self::$whatsappQueueIndex++);
                } else {
                    Log::warning('⚠️ VISITANTE_SIN_TELEFONO', ['identity_id' => $visitanteIdentity->id]);
                }
            } catch (\Throwable $e) {
                Log::error('❌ CITA_PROVEEDOR_WHATSAPP_VISITANTE_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
            }

            $this->notificarJefeSegPatrimonial(
                $request,
                "🔔 Nueva cita registrada:\n\n"
                    . "*{$nombreProveedor}* visitará a *{$nombreVisitante}*\n"
                    . "el *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                    . ($request->con_vehiculo ? "\n🚗 Asistirá con vehículo." : "\n🚗 Asistirá sin vehículo.")
                    . $notasTexto
            );

            Log::info('📞 TELEFONOS_STORE_PROVEEDOR', [
                'cita_id'            => $cita->id,
                'telefono_proveedor' => $telefonoProveedor,
                'telefono_visitante' => $telefonoVisitante,
            ]);
        }

        return compact('citasCreadas', 'errores');
    }

    /* ══════════════════════════════════════════════
     |  ACTUALIZAR — anfitrión interno
     ══════════════════════════════════════════════ */

    /**
     * @return array{citasActualizadas: Cita[], errores: string[]}|Cita
     *         Devuelve array si vinieron visitantes nuevos, o el Cita fresco si fue update simple.
     */
    public function actualizarCitaAnfitrion(Request $request, Cita $cita, UserFirebirdIdentity $identity): array|Cita
    {
        $idAnfitrion = $identity->id;
        $fecha       = $request->fecha       ?? $cita->fecha;
        $horaInicio  = $request->hora_inicio ?? $cita->hora_inicio;
        $horaFin     = $request->hora_fin    ?? $cita->hora_fin;

        $meData            = $this->userService->me($request);
        $telefonoAnfitrion = $this->obtenerTelefonoUsuario($meData['user']);
        $nombreAnfitrion   = $this->resolverNombreDeUserData($meData['user']);
        $conVehiculo       = $request->con_vehiculo ?? $cita->con_vehiculo;

        [$fechaFmt, $horaIniFmt, $horaFinFmt] = $this->formatearFechaHora($fecha, $horaInicio, $horaFin);
        $vehiculoTexto = $conVehiculo ? "\n🚗 Asistirá con vehículo." : "\n🚗 Asistirá sin vehículo.";
        $notasTexto    = ($request->notas ?? $cita->notas) ? "\n\n📝 Notas adicionales: " . ($request->notas ?? $cita->notas) : '';

        // ── Con visitantes nuevos ──────────────────────────────────────────
        if ($request->has('visitantes')) {
            $citasActualizadas = [];
            $errores           = [];

            foreach ($request->visitantes as $idProveedor) {
                $proveedorIdentity = UserFirebirdIdentity::find($idProveedor);

                if (!$proveedorIdentity) {
                    $errores[] = "Proveedor con id {$idProveedor} no encontrado.";
                    continue;
                }

                $cruce = $this->detectarCruceEnAgenda(
                    $proveedorIdentity->id, $fecha, $horaInicio, $horaFin, excludeId: $cita->id, campo: 'id_visitante'
                );

                if ($cruce) {
                    $errores[] = $this->mensajeCruce($proveedorIdentity, $cruce);
                    continue;
                }

                $nombreProv = $proveedorIdentity->firebirdUser->NOMBRE ?? null;

                $cita->update([
                    'id_visitante'     => $proveedorIdentity->id,
                    'nombre_visitante' => $nombreProv,
                    'fecha'            => $fecha,
                    'hora_inicio'      => $horaInicio,
                    'hora_fin'         => $horaFin,
                    'motivo'           => $request->motivo       ?? $cita->motivo,
                    'estado'           => $request->estado       ?? $cita->estado,
                    'notas'            => $request->notas        ?? $cita->notas,
                    'con_vehiculo'     => $request->con_vehiculo ?? $cita->con_vehiculo,
                ]);

                $citasActualizadas[] = $cita->fresh();
                $telefonoProveedor   = $this->obtenerTelefonoDeIdentity($proveedorIdentity);

                try {
                    if ($telefonoAnfitrion) {
                        $msg = "✏️ Has actualizado tu cita con *{$nombreProv}* para el día *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*."
                            . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                            . $notasTexto . $vehiculoTexto;
                        $this->enviarMensajeAlUsuario($request, $msg, $telefonoAnfitrion);
                    }
                } catch (\Throwable $e) {
                    Log::error('❌ UPDATE_WHATSAPP_ANFITRION_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
                }

                try {
                    if ($telefonoProveedor) {
                        $msg = "✏️ *{$nombreAnfitrion}* ha actualizado la cita contigo para el día *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*."
                            . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                            . $notasTexto . $vehiculoTexto
                            . ($conVehiculo ? "\n\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil." : '');
                        $this->enviarMensajeAlUsuario($request, $msg, $telefonoProveedor);
                    }
                } catch (\Throwable $e) {
                    Log::error('❌ UPDATE_WHATSAPP_PROVEEDOR_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
                }

                $this->notificarJefeSegPatrimonial(
                    $request,
                    "✏️ Cita actualizada:\n*{$nombreAnfitrion}* modificó su cita con el proveedor *{$nombreProv}*\n"
                        . "el *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*." . $vehiculoTexto . $notasTexto
                );

                Log::info('📞 TELEFONOS_UPDATE', [
                    'cita_id'            => $cita->id,
                    'telefono_anfitrion' => $telefonoAnfitrion,
                    'telefono_proveedor' => $telefonoProveedor,
                ]);
            }

            return compact('citasActualizadas', 'errores');
        }

        // ── Update simple (sin cambio de visitante) ───────────────────────
        $proveedorIdentityActual = UserFirebirdIdentity::find($cita->id_visitante);
        $nombreProvActual        = $cita->nombre_visitante ?? 'el proveedor';
        $telefonoProvActual      = $proveedorIdentityActual
            ? $this->obtenerTelefonoDeIdentity($proveedorIdentityActual)
            : null;

        $cita->update([
            'fecha'        => $fecha,
            'hora_inicio'  => $horaInicio,
            'hora_fin'     => $horaFin,
            'motivo'       => $request->motivo       ?? $cita->motivo,
            'estado'       => $request->estado       ?? $cita->estado,
            'notas'        => $request->notas        ?? $cita->notas,
            'con_vehiculo' => $request->con_vehiculo ?? $cita->con_vehiculo,
        ]);

        try {
            if ($telefonoAnfitrion) {
                $msg = "✏️ Has actualizado tu cita con *{$nombreProvActual}* para el día *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*."
                    . ($request->motivo ? "\n\n📋 Motivo: " . ($request->motivo ?? $cita->motivo) : '')
                    . $notasTexto . $vehiculoTexto;
                $this->enviarMensajeAlUsuario($request, $msg, $telefonoAnfitrion);
            }
        } catch (\Throwable $e) {
            Log::error('❌ UPDATE_SIMPLE_WHATSAPP_ANFITRION_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
        }

        try {
            if ($telefonoProvActual) {
                $msg = "✏️ *{$nombreAnfitrion}* ha actualizado la cita contigo para el día *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*."
                    . ($request->motivo ? "\n\n📋 Motivo: " . ($request->motivo ?? $cita->motivo) : '')
                    . $notasTexto . $vehiculoTexto
                    . ($conVehiculo ? "\n\n⚠️ Recuerda solicitar autorización para el ingreso con automóvil." : '');
                $this->enviarMensajeAlUsuario($request, $msg, $telefonoProvActual);
            }
        } catch (\Throwable $e) {
            Log::error('❌ UPDATE_SIMPLE_WHATSAPP_PROVEEDOR_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
        }

        $this->notificarJefeSegPatrimonial(
            $request,
            "✏️ Cita actualizada:\n*{$nombreAnfitrion}* modificó su cita con el proveedor *{$nombreProvActual}*\n"
                . "el *{$fechaFmt}* de *{$horaIniFmt}* a *{$horaFinFmt}*." . $vehiculoTexto . $notasTexto
        );

        Log::info('📞 TELEFONOS_UPDATE_SIMPLE', [
            'cita_id'            => $cita->id,
            'telefono_anfitrion' => $telefonoAnfitrion,
            'telefono_proveedor' => $telefonoProvActual,
        ]);

        return $cita->fresh();
    }

    /* ══════════════════════════════════════════════
     |  ACTUALIZAR — proveedor reagenda grupo
     ══════════════════════════════════════════════ */

    /**
     * Elimina las citas viejas del grupo y recrea con los nuevos datos.
     *
     * @return array{citasCreadas: Cita[], errores: string[]}
     */
    public function actualizarCitasProveedor(Request $request, UserFirebirdIdentity $identity): array
    {
        $idProveedor     = $identity->id;
        $citasCreadas    = [];
        $errores         = [];
        $nombreProveedor = $this->obtenerNombreProveedorDesdeIdentity($identity);

        $meData            = $this->userService->me($request);
        $telefonoProveedor = $this->obtenerTelefonoUsuario($meData['user']);

        // ── Eliminar citas viejas del grupo ──
        Cita::whereIn('id', $request->ids)
            ->where('id_user', $idProveedor)
            ->delete();

        [$fecha, $horaIni, $horaFin] = $this->formatearFechaHora($request->fecha, $request->hora_inicio, $request->hora_fin);
        $vehiculoTexto = $request->con_vehiculo ? "\n🚗 Viene en vehículo." : "\n🚗 Viene sin vehículo.";
        $notasTexto    = $request->notas ? "\n\n📝 Notas adicionales: {$request->notas}" : '';

        foreach ($request->visitantes as $idVisitante) {
            $visitanteIdentity = UserFirebirdIdentity::find($idVisitante);

            if (!$visitanteIdentity) {
                $errores[] = "Visitante con id {$idVisitante} no encontrado.";
                continue;
            }

            $cruce = $this->detectarCruceEnAgenda($visitanteIdentity->id, $request->fecha, $request->hora_inicio, $request->hora_fin);
            if ($cruce) {
                $errores[] = $this->mensajeCruce($visitanteIdentity, $cruce);
                continue;
            }

            $cita = Cita::create([
                'cita_type_id'     => 1,
                'id_user'          => $idProveedor,
                'id_visitante'     => $visitanteIdentity->id,
                'nombre_visitante' => $visitanteIdentity->firebirdUser->NOMBRE ?? null,
                'fecha'            => $request->fecha,
                'hora_inicio'      => $request->hora_inicio,
                'hora_fin'         => $request->hora_fin,
                'motivo'           => $request->motivo,
                'estado'           => $request->estado ?? 'pendiente',
                'notas'            => $request->notas,
                'con_vehiculo'     => $request->con_vehiculo ?? false,
                'created_at'       => now(),
            ]);

            $citasCreadas[]    = $cita;
            $nombreVisitante   = $visitanteIdentity->firebirdUser->NOMBRE ?? "ID {$idVisitante}";
            $telefonoVisitante = $this->obtenerTelefonoDeIdentity($visitanteIdentity);

            try {
                if ($telefonoProveedor) {
                    $msg = "✏️ Tu cita con *{$nombreVisitante}* ha sido actualizada para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . $notasTexto
                        . ($request->con_vehiculo ? "\n🚗 Asistirás con vehículo." : '');
                    $this->enviarMensajeAlUsuario($request, $msg, $telefonoProveedor);
                }
            } catch (\Throwable $e) {
                Log::error('❌ UPDATE_PROVEEDOR_WHATSAPP_PROVEEDOR_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
            }

            try {
                if ($telefonoVisitante) {
                    $msg = "Hola, *{$nombreProveedor}* ha actualizado su cita contigo para el día *{$fecha}* de *{$horaIni}* a *{$horaFin}*."
                        . ($request->motivo ? "\n\n📋 Motivo: {$request->motivo}" : '')
                        . $notasTexto . $vehiculoTexto
                        . "\n\n⚠️ Recuerda pedir autorización de dirección para el ingreso con automóvil";
                    $this->enviarMensajeAlUsuario($request, $msg, $telefonoVisitante);
                }
            } catch (\Throwable $e) {
                Log::error('❌ UPDATE_PROVEEDOR_WHATSAPP_VISITANTE_ERROR', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
            }

            $this->notificarJefeSegPatrimonial(
                $request,
                "✏️ Cita actualizada:\n\n*{$nombreProveedor}* modificó su visita con *{$nombreVisitante}*\n"
                    . "el *{$fecha}* de *{$horaIni}* a *{$horaFin}*." . $vehiculoTexto . $notasTexto
            );

            Log::info('📞 TELEFONOS_UPDATE_PROVEEDOR', [
                'cita_id'            => $cita->id,
                'telefono_proveedor' => $telefonoProveedor,
                'telefono_visitante' => $telefonoVisitante,
            ]);
        }

        return compact('citasCreadas', 'errores');
    }

    /* ══════════════════════════════════════════════
     |  CAMBIAR ESTADO
     ══════════════════════════════════════════════ */

    /**
     * Actualiza estado y notifica a ambas partes + jefe seguridad.
     *
     * @return array{sin_cambio: bool, cita: Cita}
     */
    public function actualizarEstado(Request $request, Cita $cita, UserFirebirdIdentity $identity): array
    {
        $estadoAnterior = $cita->estado;

        if ($estadoAnterior === $request->estado) {
            return ['sin_cambio' => true, 'cita' => $cita];
        }

        $cita->update(['estado' => $request->estado]);

        $anfitrionIdentity = UserFirebirdIdentity::find($cita->id_user);
        $visitanteIdentity = UserFirebirdIdentity::find($cita->id_visitante);
        $nombreAnfitrion   = $anfitrionIdentity?->firebirdUser->NOMBRE ?? 'Anfitrión';
        $nombreVisitante   = $visitanteIdentity?->firebirdUser->NOMBRE ?? 'Visitante';
        $telefonoAnfitrion = $anfitrionIdentity ? $this->obtenerTelefonoDeIdentity($anfitrionIdentity) : null;
        $telefonoVisitante = $visitanteIdentity ? $this->obtenerTelefonoDeIdentity($visitanteIdentity) : null;

        [$fecha, $horaIni, $horaFin] = $this->formatearFechaHora($cita->fecha, $cita->hora_inicio, $cita->hora_fin);

        $yoSoyAnfitrion = $identity->id === $cita->id_user;
        $quienCambia    = $yoSoyAnfitrion ? $nombreAnfitrion : $nombreVisitante;
        $miContraparte  = $yoSoyAnfitrion ? $nombreVisitante : $nombreAnfitrion;

        $mensajeEstado  = "Estado: *{$estadoAnterior}* → *{$request->estado}*";
        $mensajePropio  = "✅ Cambiaste el estado de tu cita con {$miContraparte} del dia {$fecha} de {$horaIni} a {$horaFin}.\n\n{$mensajeEstado}";
        $mensajeTercero = "⚠️ *{$quienCambia}* cambió el estado de la cita contigo del día {$fecha} de {$horaIni} a {$horaFin}.\n\n{$mensajeEstado}";
        $mensajeJefe    = "*{$quienCambia}* modificó el estado de esta cita con {$nombreAnfitrion} del dia {$fecha} de {$horaIni} a {$horaFin}\n\n{$mensajeEstado}";

        try {
            if ($telefonoAnfitrion) {
                $this->enviarMensajeAlUsuario($request, $yoSoyAnfitrion ? $mensajePropio : $mensajeTercero, $telefonoAnfitrion);
            }
        } catch (\Throwable $e) {
            Log::error('❌ UPDATE_ESTADO_WHATSAPP_ANFITRION', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
        }

        try {
            if ($telefonoVisitante) {
                $this->enviarMensajeAlUsuario($request, !$yoSoyAnfitrion ? $mensajePropio : $mensajeTercero, $telefonoVisitante);
            }
        } catch (\Throwable $e) {
            Log::error('❌ UPDATE_ESTADO_WHATSAPP_VISITANTE', ['error' => $e->getMessage(), 'cita_id' => $cita->id]);
        }

        $this->notificarJefeSegPatrimonial($request, $mensajeJefe);

        return ['sin_cambio' => false, 'cita' => $cita->fresh()];
    }

    /* ══════════════════════════════════════════════
     |  VALIDACIONES
     ══════════════════════════════════════════════ */

    /**
     * Verifica si hay cruce de horario en la agenda de un usuario.
     */
    public function hayCruceEnAgendaDeAnfitrion(
        int $idUser,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int $excludeId = null
    ): bool {
        $query = Cita::where('id_user', $idUser)
            ->where('fecha', $fecha)
            ->where('hora_inicio', '<', $horaFin)
            ->where('hora_fin', '>', $horaInicio);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /* ══════════════════════════════════════════════
     |  WHATSAPP / NOTIFICACIONES
     ══════════════════════════════════════════════ */

    public function enviarMensajeAlUsuario(Request $request, string $mensaje, string $telefono): void
    {
        $delayMinutos = self::$whatsappQueueIndex;
        self::$whatsappQueueIndex++;

        EnviarMensajeWhatsappJob::dispatch($telefono, $mensaje)
            ->delay(now()->addMinutes($delayMinutos))
            ->onQueue('whatsapp');

        Log::info('📨 WhatsApp encolado', [
            'telefono'      => $telefono,
            'delay_minutos' => $delayMinutos,
            'queue_index'   => self::$whatsappQueueIndex - 1,
        ]);
    }

    public function notificarJefeSegPatrimonial(Request $request, string $mensaje): void
    {
        try {
            $segPatrId     = (int) env('SEG_PATR_ID');
            $segPatrNombre = trim((string) env('SEG_PATR', ''));

            if (!$segPatrId || !$segPatrNombre) {
                Log::warning('⚠️ SEG_PATR o SEG_PATR_ID no configurados en .env');
                return;
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', $segPatrId)->first();

            if (!$identity) {
                Log::warning('⚠️ SEG_PATR: identity no encontrada', ['firebird_user_clave' => $segPatrId]);
                return;
            }

            $telefonoSegPatr = null;

            if ($identity->firebird_tb_clave !== null) {
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    $nombreEnvNormalizado = mb_strtoupper(trim($segPatrNombre));
                    $nombreTbCompleto     = mb_strtoupper(trim(
                        trim($tbRow->NOMBRE ?? '') . ' ' .
                        trim($tbRow->AP_PAT_ ?? '') . ' ' .
                        trim($tbRow->AP_MAT_ ?? '')
                    ));
                    $nombreTbSolo = mb_strtoupper(trim($tbRow->NOMBRE ?? ''));

                    $coincide = ($nombreTbSolo === $nombreEnvNormalizado)
                        || str_starts_with($nombreTbCompleto, $nombreEnvNormalizado)
                        || str_starts_with($nombreEnvNormalizado, $nombreTbSolo);

                    if (!$coincide) {
                        Log::warning('⚠️ SEG_PATR: nombre en .env no coincide con TB', [
                            'env' => $nombreEnvNormalizado,
                            'tb'  => $nombreTbCompleto,
                        ]);
                        return;
                    }

                    $telefonoSegPatr = $tbRow->TELEFONO
                        ?? $tbRow->TEL
                        ?? $tbRow->TEL_CELULAR
                        ?? $tbRow->CELULAR
                        ?? $tbRow->TEL_PARTICULAR
                        ?? null;
                }
            }

            if (!$telefonoSegPatr) {
                Log::warning('⚠️ SEG_PATR: sin teléfono encontrado', ['identity_id' => $identity->id]);
                return;
            }

            $this->enviarMensajeAlUsuario($request, $mensaje, $telefonoSegPatr);

            Log::info('✅ SEG_PATR notificado', [
                'identity_id' => $identity->id,
                'telefono'    => $telefonoSegPatr,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ SEG_PATR_NOTIFY_ERROR', ['error' => $e->getMessage()]);
        }
    }

    /* ══════════════════════════════════════════════
     |  HELPERS PRIVADOS
     ══════════════════════════════════════════════ */

    /**
     * Detecta cruce de horario en la agenda de un usuario (como id_user o id_visitante).
     */
    private function detectarCruceEnAgenda(
        int $idUser,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int $excludeId = null,
        string $campo = 'id_user'
    ): ?Cita {
        $query = Cita::where($campo, $idUser)
            ->where('fecha', $fecha)
            ->where('hora_inicio', '<', $horaFin)
            ->where('hora_fin', '>', $horaInicio);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    /**
     * Construye el mensaje de error de cruce de horario.
     */
    private function mensajeCruce(UserFirebirdIdentity $identity, Cita $cruce): string
    {
        $nombre      = $identity->firebirdUser->NOMBRE ?? "ID {$identity->id}";
        $horaIni     = Carbon::parse($cruce->hora_inicio)->format('g:i') . ' ' .
            (Carbon::parse($cruce->hora_inicio)->format('A') === 'AM' ? 'am' : 'pm');
        $horaFin     = Carbon::parse($cruce->hora_fin)->format('g:i') . ' ' .
            (Carbon::parse($cruce->hora_fin)->format('A') === 'AM' ? 'am' : 'pm');

        return "{$nombre} ya tiene una cita de {$horaIni} a {$horaFin}.";
    }

    /**
     * Adjunta nombre del organizador a una cita externa.
     */
    private function adjuntarNombreOrganizador(Cita $cita): void
    {
        try {
            $organizadorIdentity = UserFirebirdIdentity::find($cita->id_user);
            $nombre = null;

            if ($organizadorIdentity) {
                $nombre = $this->notif->obtenerNombrePorIdentity($organizadorIdentity)
                    ?? $organizadorIdentity->firebirdUser->NOMBRE
                    ?? null;
            }

            if ($cita->cita_type_id === 2) {
                $cita->nombre_organizador = $nombre;
            } else {
                $cita->nombre_proveedor = $nombre;
            }
        } catch (\Throwable $e) {
            Log::error('❌ NOMBRE_ORGANIZADOR_ERROR', ['error' => $e->getMessage()]);
            $cita->nombre_proveedor   = null;
            $cita->nombre_organizador = null;
        }
    }

    /**
     * Devuelve [fecha, horaInicio, horaFin] formateadas en español.
     *
     * @return array{string, string, string}
     */
    private function formatearFechaHora(string $fecha, string $horaInicio, string $horaFin): array
    {
        $fmt = fn(string $hora) => Carbon::parse($hora)->format('g:i') . ' ' .
            (Carbon::parse($hora)->format('A') === 'AM' ? 'am' : 'pm');

        return [
            Carbon::parse($fecha)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
            $fmt($horaInicio),
            $fmt($horaFin),
        ];
    }

    /**
     * Extrae el nombre del usuario desde el array devuelto por UserService::me().
     */
    private function resolverNombreDeUserData(array $userData): string
    {
        return $userData['TB']->NOMBRE
            ?? $userData['CLIE']->NOMBRE
            ?? $userData['VEND']->NOMBRE
            ?? $userData['PROV']?->NOMBRE
            ?? 'Un colaborador';
    }

    /**
     * Obtiene teléfono a partir del array de UserService::me()['user'].
     */
    public function obtenerTelefonoUsuario(array $userData): ?string
    {
        $tipo = $userData['tipo_usuario'] ?? null;

        $obj = match ($tipo) {
            'empleado'  => $userData['TB']   ?? null,
            'cliente'   => $userData['CLIE'] ?? null,
            'vendedor'  => $userData['VEND'] ?? null,
            default     => null,
        };

        if (!$obj) return null;

        return $obj->TELEFONO
            ?? $obj->TEL
            ?? $obj->TEL_CELULAR
            ?? $obj->CELULAR
            ?? $obj->TEL_PARTICULAR
            ?? null;
    }

    /**
     * Obtiene teléfono directamente desde la identity (sin pasar por UserService).
     */
    public function obtenerTelefonoDeIdentity(UserFirebirdIdentity $identity): ?string
    {
        // 🏢 EMPLEADO
        if ($identity->firebird_tb_clave !== null) {
            try {
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                if ($tbRow) {
                    return $tbRow->TELEFONO
                        ?? $tbRow->TEL
                        ?? $tbRow->TEL_CELULAR
                        ?? $tbRow->CELULAR
                        ?? $tbRow->TEL_PARTICULAR
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_EMPLEADO_ERROR', ['identity_id' => $identity->id, 'error' => $e->getMessage()]);
            }

            return null;
        }

        // 🛒 CLIENTE
        if ($identity->firebird_clie_clave !== null) {
            try {
                $row = $this->firebird->getProductionConnection()
                    ->selectOne("SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM CLIE03 WHERE CLAVE = ?", [$identity->firebird_clie_clave]);

                if ($row) return $row->TELEFONO ?? $row->TEL ?? $row->CELULAR ?? $row->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_CLIENTE_ERROR', ['identity_id' => $identity->id, 'error' => $e->getMessage()]);
            }

            return null;
        }

        // 🧑‍💼 VENDEDOR
        if ($identity->firebird_vend_clave !== null) {
            try {
                $row = $this->firebird->getProductionConnection()
                    ->selectOne("SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM VEND03 WHERE CVE_VEND = ?", [$identity->firebird_vend_clave]);

                if ($row) return $row->TELEFONO ?? $row->TEL ?? $row->CELULAR ?? $row->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_VENDEDOR_ERROR', ['identity_id' => $identity->id, 'error' => $e->getMessage()]);
            }

            return null;
        }

        // 📦 PROVEEDOR
        if ($identity->firebird_prov_clave !== null) {
            try {
                $row = $this->firebird->getProductionConnection()
                    ->selectOne("SELECT TELEFONO, TEL, CELULAR, TEL_CELULAR FROM PROV03 WHERE CLAVE = ?", [$identity->firebird_prov_clave]);

                if ($row) return $row->TELEFONO ?? $row->TEL ?? $row->CELULAR ?? $row->TEL_CELULAR ?? null;
            } catch (\Throwable $e) {
                Log::error('❌ TELEFONO_IDENTITY_PROVEEDOR_ERROR', ['identity_id' => $identity->id, 'error' => $e->getMessage()]);
            }

            return null;
        }

        return null;
    }

    /**
     * Obtiene el nombre del proveedor/visitante directo desde Firebird.
     */
    public function obtenerNombreProveedorDesdeIdentity(UserFirebirdIdentity $identity): string
    {
        try {
            if ($identity->firebird_tb_clave !== null) {
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $tbClave     = trim((string) $identity->firebird_tb_clave);
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tbRow = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string) $row->CLAVE))
                    ->get($tbClave);

                return $tbRow->NOMBRE ?? 'Sin nombre';
            }

            $conn = $this->firebird->getProductionConnection();

            if (!empty($identity->firebird_clie_clave)) {
                $row = $conn->selectOne("SELECT NOMBRE FROM CLIE03 WHERE CLAVE = ?", [$identity->firebird_clie_clave]);
                return $row?->NOMBRE ?? 'Sin nombre';
            }

            if ($identity->firebird_vend_clave !== null) {
                $row = $conn->selectOne("SELECT NOMBRE FROM VEND03 WHERE CVE_VEND = ?", [$identity->firebird_vend_clave]);
                return $row?->NOMBRE ?? 'Sin nombre';
            }

            if ($identity->firebird_prov_clave !== null) {
                $row = $conn->selectOne("SELECT NOMBRE FROM PROV03 WHERE TRIM(CLAVE) = ?", [trim((string) $identity->firebird_prov_clave)]);
                return $row?->NOMBRE ?? 'Sin nombre';
            }
        } catch (\Throwable $e) {
            Log::error('❌ NOMBRE_PROVEEDOR_ERROR', ['error' => $e->getMessage()]);
        }

        return 'Sin nombre';
    }
}