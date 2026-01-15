<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class UsuarioFullResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            // Datos básicos
            'id'                => $this->id,
            'name'              => $this->nombre,
            'email'             => $this->correo,
            'usuario'           => $this->usuario,
            'curp'              => $this->curp,
            'telefono'          => $this->telefono,
            'status_id'         => $this->status_id,
            'departamento_id'   => $this->departamento_id,
            'direccion_id'      => $this->direccion_id,
            'photo'             => $this->photo,
            'created_at'        => $this->created_at ? $this->created_at->format('d/m/Y H:i:s') : null,
            'updated_at'        => $this->updated_at ? $this->updated_at->format('d/m/Y H:i:s') : null,
            'permissions'       => $this->roles()->pluck('ROLE_CLAVE'),

            // Relación: Status
            'status' => $this->status ? [
                'id' => $this->status->id,
                'nombre' => $this->status->nombre,
                'descripcion' => $this->status->descripcion,
            ] : null,

            // Relación: Dirección
            'direccion' => $this->direccion ? [
                'id'                 => $this->direccion->id,
                'calle'              => $this->direccion->calle,
                'no_ext'             => $this->direccion->no_ext,
                'no_int'             => $this->direccion->no_int,
                'colonia'            => $this->direccion->colonia,
                'cp'                 => $this->direccion->cp,
                'municipio'          => $this->direccion->municipio,
                'estado'             => $this->direccion->estado,
                'entidad_federativa' => $this->direccion->entidad_federativa,
                'pais'               => $this->direccion->pais,
            ] : null,

            'departamento' => $this->departamento ? [
                'id'     => $this->departamento->id,
                'nombre' => $this->departamento->nombre,
            ] : null,

            'roles' => $this->roles->map(function ($role) {
                return [
                    'id'              => $role->id,
                    'ROLE_CLAVE'      => $role->ROLE_CLAVE,
                    'MODEL_CLAVE'     => $role->MODEL_CLAVE,
                    'SUBROL_ID'       => $role->SUBROL_ID,
                    'MODEL_TYPE'      => $role->MODEL_TYPE,
                    'subrol'          => $role->subrol ? [
                        'id'         => $role->subrol->id,
                        'nombre'     => $role->subrol->nombre,
                        'GUARD_NAME' => $role->subrol->GUARD_NAME,
                    ] : null,
                ];
            }),

            'MODEL_HAS_STATUSES' => $this->modelHasStatuses->map(function ($mhs) {
                return [
                    'id'             => $mhs->id,
                    'status_id'      => $mhs->status_id,
                    'user_id'        => $mhs->user_id,
                    'status_nombre'  => $mhs->status ? $mhs->status->nombre : null,
                    'status_descripcion' => $mhs->status ? $mhs->status->descripcion : null,
                    'created_at'     => $mhs->created_at ? $mhs->created_at->format('d/m/Y H:i:s') : null,
                ];
            }),

            // Relación: Empleos
            'empleos' => $this->empleos->map(function ($empleo) {
                return [
                    'id'            => $empleo->id,
                    'puesto'        => $empleo->puesto,
                    'fecha_inicio'  => $empleo->fecha_inicio ? Carbon::parse($empleo->fecha_inicio)->format('d/m/Y') : null,
                    'fecha_fin'     => $empleo->fecha_fin ? Carbon::parse($empleo->fecha_fin)->format('d/m/Y') : null,
                    'comentarios'   => $empleo->comentarios,
                ];
            }),

            // Relación: Fiscal
            'fiscal' => $this->fiscal->first() ? [
                'id'             => $this->fiscal->first()->id,
                'rfc'            => $this->fiscal->first()->rfc,
                'regimen_fiscal' => $this->fiscal->first()->regimen_fiscal,
            ] : null,


            // Relación: Seguridad Social
            'seguridad_social' => $this->seguridadSocial->first() ? [
                'id'          => $this->seguridadSocial->first()->id,
                'numero_imss' => $this->seguridadSocial->first()->numero_imss,
                'fecha_alta'  => $this->seguridadSocial->first()->fecha_alta ? Carbon::parse($this->seguridadSocial->first()->fecha_alta)->format('d/m/Y') : null,
                'tipo_seguro' => $this->seguridadSocial->first()->tipo_seguro,
            ] : null,

            // Relación: Nómina
            'nomina' => $this->nomina->first() ? [
                'id'                  => $this->nomina->first()->id,
                'numero_tarjeta'      => $this->nomina->first()->numero_tarjeta,
                'banco'               => $this->nomina->first()->banco,
                'clabe_interbancaria' => $this->nomina->first()->clabe_interbancaria,
                'salario_base'        => $this->nomina->first()->salario_base,
                'frecuencia_pago'     => $this->nomina->first()->frecuencia_pago,
            ] : null,

            'sueldo' => $this->sueldo ? [
                'id'             => $this->sueldo->id,
                'sueldo_diario'  => $this->sueldo->sueldo_diario,
                'sueldo_semanal'  => $this->sueldo->sueldo_semanal,
                'sueldo_mensual' => $this->sueldo->sueldo_mensual,
                'sueldo_anual'   => $this->sueldo->sueldo_anual,
            ] : null,


            'sueldos_historial' => $this->sueldo && $this->sueldo->historial ?
                $this->sueldo->historial->map(function ($historial) {
                    return [
                        'id'             => $historial->id,
                        'sueldo_diario'  => $historial->sueldo_diario,
                        'sueldo_mensual' => $historial->sueldo_mensual,
                        'sueldo_anual'   => $historial->sueldo_anual,
                        'comentarios'    => $historial->comentarios,
                        'created_at'     => $historial->created_at ? $historial->created_at->format('d/m/Y H:i:s') : null,
                    ];
                }) : [],

            'departamento_historial' => $this->departamentosHistorial->map(function ($historial) {
                return [
                    'id'              => $historial->id,
                    'departamento_id' => $historial->departamento_id,
                    'departamento'    => $historial->departamento ? $historial->departamento->nombre : null,
                    'fecha_inicio'    => $historial->fecha_inicio ? Carbon::parse($historial->fecha_inicio)->format('d/m/Y') : null,
                    'fecha_fin'       => $historial->fecha_fin ? Carbon::parse($historial->fecha_fin)->format('d/m/Y') : null,
                    'comentarios'     => $historial->comentarios,
                ];
            }),


            // Relación: Departamento
            'departamento' => $this->departamento ? [
                'id' => $this->departamento->id,
                'nombre' => $this->departamento->nombre,
            ] : null,
            'vacaciones' => $this->vacaciones->map(function ($vacacion) {
                return [
                    'id'               => $vacacion->id,
                    'anio'             => $vacacion->anio,
                    'dias_totales'     => $vacacion->dias_totales,
                    'dias_disfrutados' => $vacacion->dias_disfrutados,
                    'dias_disponibles' => $vacacion->dias_totales - $vacacion->dias_disfrutados,
                    'historial'        => $vacacion->historial->map(function ($hist) {
                        return [
                            'id'           => $hist->id,
                            'fecha_inicio' => $hist->fecha_inicio ? Carbon::parse($hist->fecha_inicio)->format('d/m/Y') : null,
                            'fecha_fin'    => $hist->fecha_fin ? Carbon::parse($hist->fecha_fin)->format('d/m/Y') : null,
                            'dias'         => $hist->dias,
                            'comentarios'  => $hist->comentarios,
                            'created_at'   => $hist->created_at ? $hist->created_at->format('d/m/Y H:i:s') : null,
                        ];
                    }),
                ];
            }),


            'asistencias' => $this->asistencias->map(function ($asistencia) {
                return [
                    'id'           => $asistencia->id,
                    'fecha'        => $asistencia->fecha ? Carbon::parse($asistencia->fecha)->format('d/m/Y') : null,
                    'hora_entrada' => $asistencia->hora_entrada,
                    'hora_salida'  => $asistencia->hora_salida,
                    'created_at'   => $asistencia->created_at ? $asistencia->created_at->format('d/m/Y H:i:s') : null,
                ];
            }),


            // Relación: ModelHasStatus (todos los registros)
            'MODEL_HAS_STATUSES' => $this->modelHasStatuses->map(function ($mhs) {
                return [
                    'id'          => $mhs->id,
                    'nombre'      => $mhs->nombre,          // nombre específico de este registro
                    'status_id'   => $mhs->status_id,
                    'status_nombre' => $mhs->status ? $mhs->status->nombre : null, // nombre del status real
                    'user_id'     => $mhs->user_id,
                ];
            }),


            'notificaciones' => $this->notificaciones->map(function ($notif) {
                return [
                    'id'          => $notif->id,
                    'titulo'      => $notif->titulo,
                    'mensaje'     => $notif->mensaje,
                    'leido'       => $notif->leido,
                    'fecha_envio' => $notif->fecha_envio ? Carbon::parse($notif->fecha_envio)->format('d/m/Y H:i:s') : null,
                ];
            }),


            'bonos' => $this->bonos->map(function ($bono) {
                return [
                    'id'       => $bono->id,
                    'monto'    => $bono->monto,
                    'concepto' => $bono->concepto,
                    'fecha'    => $bono->fecha ? Carbon::parse($bono->fecha)->format('d/m/Y') : null,
                ];
            }),


            'tiempos_extra' => $this->tiemposExtra->map(function ($tiempo) {
                return [
                    'id'          => $tiempo->id,
                    'fecha'       => $tiempo->fecha ? Carbon::parse($tiempo->fecha)->format('d/m/Y') : null,
                    'hora_inicio' => $tiempo->hora_inicio,
                    'hora_fin'    => $tiempo->hora_fin,
                    'horas'       => $tiempo->horas,
                    'motivo'      => $tiempo->motivo,
                ];
            }),

            'turno' => $this->turno ? [
                'id'          => $this->turno->id,
                'nombre'      => $this->turno->nombre,
                'hora_inicio' => $this->turno->hora_inicio,
                'hora_fin'    => $this->turno->hora_fin,
                'descripcion' => $this->turno->descripcion,
            ] : null,

            'password_resets' => $this->passwordResets->map(function ($reset) {
                return [
                    'id'         => $reset->id,
                    'email'      => $reset->email,
                    'token'      => $reset->token,
                    'created_at' => $reset->created_at ? Carbon::parse($reset->created_at)->format('d/m/Y H:i:s') : null,
                ];
            }),
            'password_resets' => $this->passwordResets->map(function ($reset) {
                return [
                    'id'         => $reset->id,
                    'email'      => $reset->email,
                    'token'      => $reset->token,
                    'created_at' => $reset->created_at ? Carbon::parse($reset->created_at)->format('d/m/Y H:i:s') : null,
                ];
            }),



            // WorkOrders solicitadas por el usuario
            'workorders_solicitadas' => $this->workordersSolicitadas->map(function ($wo) {
                return [
                    'id'               => $wo->id,
                    'titulo'           => $wo->titulo,
                    'descripcion'      => $wo->descripcion,
                    'status_id'        => $wo->status_id,
                    'status'           => $wo->status ? $wo->status->nombre : null,
                    'fecha_solicitud'  => $wo->fecha_solicitud ? Carbon::parse($wo->fecha_solicitud)->format('d/m/Y H:i:s') : null,
                    'fecha_aprobacion' => $wo->fecha_aprobacion ? Carbon::parse($wo->fecha_aprobacion)->format('d/m/Y H:i:s') : null,
                    'fecha_cierre'     => $wo->fecha_cierre ? Carbon::parse($wo->fecha_cierre)->format('d/m/Y H:i:s') : null,
                ];
            }),

            // WorkOrders donde el usuario es aprobador
            'workorders_aprobadas' => $this->workordersAprobadas->map(function ($wo) {
                return [
                    'id'               => $wo->id,
                    'titulo'           => $wo->titulo,
                    'descripcion'      => $wo->descripcion,
                    'status_id'        => $wo->status_id,
                    'status'           => $wo->status ? $wo->status->nombre : null,
                    'solicitante'      => $wo->solicitante ? $wo->solicitante->nombre : null,
                    'fecha_solicitud'  => $wo->fecha_solicitud ? Carbon::parse($wo->fecha_solicitud)->format('d/m/Y H:i:s') : null,
                    'fecha_aprobacion' => $wo->fecha_aprobacion ? Carbon::parse($wo->fecha_aprobacion)->format('d/m/Y H:i:s') : null,
                    'fecha_cierre'     => $wo->fecha_cierre ? Carbon::parse($wo->fecha_cierre)->format('d/m/Y H:i:s') : null,
                ];
            }),

        ];
    }
}
