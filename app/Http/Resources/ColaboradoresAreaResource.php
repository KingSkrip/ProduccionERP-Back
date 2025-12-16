<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ColaboradoresAreaResource extends ResourceCollection
{
    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'data' => $this->collection->map(function ($usuario) {
                return [
                    // Datos básicos
                    'id'                => $usuario->id,
                    'name'              => $usuario->nombre,
                    'email'             => $usuario->correo,
                    'usuario'           => $usuario->usuario,
                    'curp'              => $usuario->curp,
                    'telefono'          => $usuario->telefono,
                    'status_id'         => $usuario->status_id,
                    'departamento_id'   => $usuario->departamento_id,
                    'direccion_id'      => $usuario->direccion_id,
                    'photo'             => $usuario->photo,
                    'created_at'        => $usuario->created_at ? $usuario->created_at->format('d/m/Y H:i:s') : null,
                    'updated_at'        => $usuario->updated_at ? $usuario->updated_at->format('d/m/Y H:i:s') : null,
                    'permissions'       => $usuario->roles()->pluck('role_clave'),

                    // Relación: Status
                    'status' => $usuario->status ? [
                        'id' => $usuario->status->id,
                        'nombre' => $usuario->status->nombre,
                        'descripcion' => $usuario->status->descripcion,
                    ] : null,

                    // Relación: Dirección
                    'direccion' => $usuario->direccion ? [
                        'id'                 => $usuario->direccion->id,
                        'calle'              => $usuario->direccion->calle,
                        'no_ext'             => $usuario->direccion->no_ext,
                        'no_int'             => $usuario->direccion->no_int,
                        'colonia'            => $usuario->direccion->colonia,
                        'cp'                 => $usuario->direccion->cp,
                        'municipio'          => $usuario->direccion->municipio,
                        'estado'             => $usuario->direccion->estado,
                        'entidad_federativa' => $usuario->direccion->entidad_federativa,
                        'pais'               => $usuario->direccion->pais,
                    ] : null,

                    'departamento' => $usuario->departamento ? [
                        'id'     => $usuario->departamento->id,
                        'nombre' => $usuario->departamento->nombre,
                    ] : null,

                    'roles' => $usuario->roles->map(function ($role) {
                        return [
                            'id'              => $role->id,
                            'role_clave'      => $role->role_clave,
                            'model_clave'     => $role->model_clave,
                            'subrol_id'       => $role->subrol_id,
                            'model_type'      => $role->model_type,
                            'subrol'          => $role->subrol ? [
                                'id'         => $role->subrol->id,
                                'nombre'     => $role->subrol->nombre,
                                'guard_name' => $role->subrol->guard_name,
                            ] : null,
                        ];
                    }),

                    'model_has_statuses' => $usuario->modelHasStatuses->map(function ($mhs) {
                        return [
                            'id'                 => $mhs->id,
                            'status_id'          => $mhs->status_id,
                            'user_id'            => $mhs->user_id,
                            'status_nombre'      => $mhs->status ? $mhs->status->nombre : null,
                            'status_descripcion' => $mhs->status ? $mhs->status->descripcion : null,
                            'created_at'         => $mhs->created_at ? $mhs->created_at->format('d/m/Y H:i:s') : null,
                        ];
                    }),

                    // Relación: Empleos
                    'empleos' => $usuario->empleos->map(function ($empleo) {
                        return [
                            'id'            => $empleo->id,
                            'puesto'        => $empleo->puesto,
                            'fecha_inicio'  => $empleo->fecha_inicio ? \Carbon\Carbon::parse($empleo->fecha_inicio)->format('d/m/Y') : null,
                            'fecha_fin'     => $empleo->fecha_fin ? \Carbon\Carbon::parse($empleo->fecha_fin)->format('d/m/Y') : null,
                            'comentarios'   => $empleo->comentarios,
                        ];
                    }),

                    // Relación: Fiscal
                    'fiscal' => $usuario->fiscal->first() ? [
                        'id'             => $usuario->fiscal->first()->id,
                        'rfc'            => $usuario->fiscal->first()->rfc,
                        'regimen_fiscal' => $usuario->fiscal->first()->regimen_fiscal,
                    ] : null,

                    // Relación: Seguridad Social
                    'seguridad_social' => $usuario->seguridadSocial->first() ? [
                        'id'          => $usuario->seguridadSocial->first()->id,
                        'numero_imss' => $usuario->seguridadSocial->first()->numero_imss,
                        'fecha_alta'  => $usuario->seguridadSocial->first()->fecha_alta ? \Carbon\Carbon::parse($usuario->seguridadSocial->first()->fecha_alta)->format('d/m/Y') : null,
                        'tipo_seguro' => $usuario->seguridadSocial->first()->tipo_seguro,
                    ] : null,

                    // Relación: Nómina
                    'nomina' => $usuario->nomina->first() ? [
                        'id'                  => $usuario->nomina->first()->id,
                        'numero_tarjeta'      => $usuario->nomina->first()->numero_tarjeta,
                        'banco'               => $usuario->nomina->first()->banco,
                        'clabe_interbancaria' => $usuario->nomina->first()->clabe_interbancaria,
                        'salario_base'        => $usuario->nomina->first()->salario_base,
                        'frecuencia_pago'     => $usuario->nomina->first()->frecuencia_pago,
                    ] : null,

                    'sueldo' => $usuario->sueldo ? [
                        'id'              => $usuario->sueldo->id,
                        'sueldo_diario'   => $usuario->sueldo->sueldo_diario,
                        'sueldo_semanal'  => $usuario->sueldo->sueldo_semanal,
                        'sueldo_mensual'  => $usuario->sueldo->sueldo_mensual,
                        'sueldo_anual'    => $usuario->sueldo->sueldo_anual,
                    ] : null,

                    'sueldos_historial' => $usuario->sueldo && $usuario->sueldo->historial ?
                        $usuario->sueldo->historial->map(function ($historial) {
                            return [
                                'id'             => $historial->id,
                                'sueldo_diario'  => $historial->sueldo_diario,
                                'sueldo_mensual' => $historial->sueldo_mensual,
                                'sueldo_anual'   => $historial->sueldo_anual,
                                'comentarios'    => $historial->comentarios,
                                'created_at'     => $historial->created_at ? $historial->created_at->format('d/m/Y H:i:s') : null,
                            ];
                        }) : [],

                    'departamento_historial' => $usuario->departamentosHistorial->map(function ($historial) {
                        return [
                            'id'              => $historial->id,
                            'departamento_id' => $historial->departamento_id,
                            'departamento'    => $historial->departamento ? $historial->departamento->nombre : null,
                            'fecha_inicio'    => $historial->fecha_inicio ? \Carbon\Carbon::parse($historial->fecha_inicio)->format('d/m/Y') : null,
                            'fecha_fin'       => $historial->fecha_fin ? \Carbon\Carbon::parse($historial->fecha_fin)->format('d/m/Y') : null,
                            'comentarios'     => $historial->comentarios,
                        ];
                    }),

                    'vacaciones' => $usuario->vacaciones->map(function ($vacacion) {
                        return [
                            'id'               => $vacacion->id,
                            'anio'             => $vacacion->anio,
                            'dias_totales'     => $vacacion->dias_totales,
                            'dias_disfrutados' => $vacacion->dias_disfrutados,
                            'dias_disponibles' => $vacacion->dias_totales - $vacacion->dias_disfrutados,
                            'historial'        => $vacacion->historial->map(function ($hist) {
                                return [
                                    'id'           => $hist->id,
                                    'fecha_inicio' => $hist->fecha_inicio ? \Carbon\Carbon::parse($hist->fecha_inicio)->format('d/m/Y') : null,
                                    'fecha_fin'    => $hist->fecha_fin ? \Carbon\Carbon::parse($hist->fecha_fin)->format('d/m/Y') : null,
                                    'dias'         => $hist->dias,
                                    'comentarios'  => $hist->comentarios,
                                    'created_at'   => $hist->created_at ? $hist->created_at->format('d/m/Y H:i:s') : null,
                                ];
                            }),
                        ];
                    }),

                    'asistencias' => $usuario->asistencias->map(function ($asistencia) {
                        return [
                            'id'           => $asistencia->id,
                            'user_id'      => $asistencia->user_id,
                            'turno'        => $asistencia->turno ? [
                                'id'          => $asistencia->turno->id,
                                'nombre'      => $asistencia->turno->nombre,
                                'hora_inicio' => $asistencia->turno->hora_inicio,
                                'hora_fin'    => $asistencia->turno->hora_fin,
                            ] : null,
                            'fecha'        => $asistencia->fecha ? \Carbon\Carbon::parse($asistencia->fecha)->format('d/m/Y') : null,
                            'hora_entrada' => $asistencia->hora_entrada,
                            'hora_salida'  => $asistencia->hora_salida,
                            'created_at'   => $asistencia->created_at ? $asistencia->created_at->format('d/m/Y H:i:s') : null,
                        ];
                    }),

                    'notificaciones' => $usuario->notificaciones->map(function ($notif) {
                        return [
                            'id'          => $notif->id,
                            'titulo'      => $notif->titulo,
                            'mensaje'     => $notif->mensaje,
                            'leido'       => $notif->leido,
                            'fecha_envio' => $notif->fecha_envio ? \Carbon\Carbon::parse($notif->fecha_envio)->format('d/m/Y H:i:s') : null,
                        ];
                    }),

                    'bonos' => $usuario->bonos->map(function ($bono) {
                        return [
                            'id'       => $bono->id,
                            'monto'    => $bono->monto,
                            'concepto' => $bono->concepto,
                            'fecha'    => $bono->fecha ? \Carbon\Carbon::parse($bono->fecha)->format('d/m/Y') : null,
                        ];
                    }),

                    'tiempos_extra' => $usuario->tiemposExtra->map(function ($tiempo) {
                        return [
                            'id'          => $tiempo->id,
                            'fecha'       => $tiempo->fecha ? \Carbon\Carbon::parse($tiempo->fecha)->format('d/m/Y') : null,
                            'hora_inicio' => $tiempo->hora_inicio,
                            'hora_fin'    => $tiempo->hora_fin,
                            'horas'       => $tiempo->horas,
                            'motivo'      => $tiempo->motivo,
                        ];
                    }),

                    'turno' => $usuario->turno ? [
                        'id'          => $usuario->turno->id,
                        'nombre'      => $usuario->turno->nombre,
                        'hora_inicio' => $usuario->turno->hora_inicio,
                        'hora_fin'    => $usuario->turno->hora_fin,
                        'descripcion' => $usuario->turno->descripcion,
                    ] : null,

                    'password_resets' => $usuario->passwordResets->map(function ($reset) {
                        return [
                            'id'         => $reset->id,
                            'email'      => $reset->email,
                            'token'      => $reset->token,
                            'created_at' => $reset->created_at ? \Carbon\Carbon::parse($reset->created_at)->format('d/m/Y H:i:s') : null,
                        ];
                    }),

                    // WorkOrders solicitadas por el usuario
                    'workorders_solicitadas' => $usuario->workordersSolicitadas->map(function ($wo) {
                        return [
                            'id'               => $wo->id,
                            'titulo'           => $wo->titulo,
                            'descripcion'      => $wo->descripcion,
                            'status_id'        => $wo->status_id,
                            'status'           => $wo->status ? $wo->status->nombre : null,
                            'fecha_solicitud'  => $wo->fecha_solicitud ? \Carbon\Carbon::parse($wo->fecha_solicitud)->format('d/m/Y H:i:s') : null,
                            'fecha_aprobacion' => $wo->fecha_aprobacion ? \Carbon\Carbon::parse($wo->fecha_aprobacion)->format('d/m/Y H:i:s') : null,
                            'fecha_cierre'     => $wo->fecha_cierre ? \Carbon\Carbon::parse($wo->fecha_cierre)->format('d/m/Y H:i:s') : null,
                        ];
                    }),

                    // WorkOrders donde el usuario es aprobador
                    'workorders_aprobadas' => $usuario->workordersAprobadas->map(function ($wo) {
                        return [
                            'id'               => $wo->id,
                            'titulo'           => $wo->titulo,
                            'descripcion'      => $wo->descripcion,
                            'status_id'        => $wo->status_id,
                            'status'           => $wo->status ? $wo->status->nombre : null,
                            'solicitante'      => $wo->solicitante ? $wo->solicitante->nombre : null,
                            'fecha_solicitud'  => $wo->fecha_solicitud ? \Carbon\Carbon::parse($wo->fecha_solicitud)->format('d/m/Y H:i:s') : null,
                            'fecha_aprobacion' => $wo->fecha_aprobacion ? \Carbon\Carbon::parse($wo->fecha_aprobacion)->format('d/m/Y H:i:s') : null,
                            'fecha_cierre'     => $wo->fecha_cierre ? \Carbon\Carbon::parse($wo->fecha_cierre)->format('d/m/Y H:i:s') : null,
                        ];
                    }),
                ];
            }),
            'total' => $this->collection->count(),
        ];
    }
}
