<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

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
            'permissions'       => $this->roles()->pluck('role_clave'),

            // Relación: Status
            'status' => $this->status ? [
                'id' => $this->status->id,
                'nombre' => $this->status->nombre,
                'descripcion' => $this->status->descripcion,
            ] : null,

            // Relación: Dirección
            'direccion' => $this->direccion ? [
                'calle'             => $this->direccion->calle,
                'no_ext'            => $this->direccion->no_ext,
                'no_int'            => $this->direccion->no_int,
                'colonia'           => $this->direccion->colonia,
                'cp'                => $this->direccion->cp,
                'municipio'         => $this->direccion->municipio,
                'estado'            => $this->direccion->estado,
                'entidad_federativa' => $this->direccion->entidad_federativa,
            ] : null,

            // Relación: Empleos
            'empleos' => $this->empleos->map(function ($empleo) {
                return [
                    'puesto'        => $empleo->puesto,
                    'fecha_inicio'  => $empleo->fecha_inicio ? \Carbon\Carbon::parse($empleo->fecha_inicio)->format('d/m/Y') : null,
                    'fecha_fin'     => $empleo->fecha_fin ? \Carbon\Carbon::parse($empleo->fecha_fin)->format('d/m/Y') : null,
                    'comentarios'   => $empleo->comentarios,
                ];
            }),

            // Relación: Fiscal
            'fiscal' => $this->fiscal->first() ? [
                'rfc'            => $this->fiscal->first()->rfc,
                'curp'           => $this->fiscal->first()->curp,
                'regimen_fiscal' => $this->fiscal->first()->regimen_fiscal,
            ] : null,

            // Relación: Seguridad Social
            'seguridad_social' => $this->seguridadSocial->first() ? [
                'numero_imss'    => $this->seguridadSocial->first()->numero_imss,
                'fecha_alta'     => $this->seguridadSocial->first()->fecha_alta ? \Carbon\Carbon::parse($this->seguridadSocial->first()->fecha_alta)->format('d/m/Y') : null,
                'tipo_seguro'    => $this->seguridadSocial->first()->tipo_seguro,
            ] : null,

            // Relación: Nómina
            'nomina' => $this->nomina->first() ? [
                'numero_tarjeta'     => $this->nomina->first()->numero_tarjeta,
                'banco'              => $this->nomina->first()->banco,
                'clabe_interbancaria' => $this->nomina->first()->clabe_interbancaria,
                'salario_base'       => $this->nomina->first()->salario_base,
                'frecuencia_pago'    => $this->nomina->first()->frecuencia_pago,
            ] : null,

            // Relación: Departamento
            'departamento' => $this->departamento ? [
                'id' => $this->departamento->id,
                'nombre' => $this->departamento->nombre,
                'cuenta_coi' => $this->departamento->cuenta_coi,
                'clasificacion' => $this->departamento->clasificacion,
                'costo' => $this->departamento->costo,
            ] : null,


            // Relación: ModelHasStatus (todos los registros)
            'model_has_statuses' => $this->modelHasStatuses->map(function ($mhs) {
                return [
                    'id'          => $mhs->id,
                    'nombre'      => $mhs->nombre,          // nombre específico de este registro
                    'status_id'   => $mhs->status_id,
                    'status_nombre' => $mhs->status ? $mhs->status->nombre : null, // nombre del status real
                    'user_id'     => $mhs->user_id,
                ];
            }),

        ];
    }
}
