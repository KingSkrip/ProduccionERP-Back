<?php

namespace App\Http\Resources\Checador;

use Illuminate\Http\Resources\Json\JsonResource;

class ChecadorPermisoResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'identity_id' => $this->user_firebird_identity_id,
            'catalogo' => $this->whenLoaded('catalogo', fn () => [
                'clave' => $this->catalogo->clave,
                'nombre' => $this->catalogo->nombre,
            ]),
            'tipo' => $this->tipo,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'hora_inicio' => $this->hora_inicio,
            'hora_fin' => $this->hora_fin,
            'motivo' => $this->motivo,
            'estado' => $this->estado,
            'aprobado_por' => $this->aprobado_por,
            'fecha_resolucion' => $this->fecha_resolucion,
            'comentarios_aprobador' => $this->comentarios_aprobador,
        ];
    }
}