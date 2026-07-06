<?php

namespace App\Http\Resources\Checador;

use Illuminate\Http\Resources\Json\JsonResource;

class ChecadorRegistroResource extends JsonResource
{
    /**
     * $this->resource es el array que regresa ChecadorQrService::registrarChecada()
     */
    public function toArray($request)
    {
        $registro = $this->resource['registro'];
        $permiso = $this->resource['permiso'];

        return [
            'tipo' => $this->resource['tipo'],
            'hora' => $registro->hora,
            'fecha' => $registro->fecha,
            'usuario' => $this->resource['usuario_nombre'],
            'en_permiso' => (bool) $permiso,
            'permiso' => $permiso?->only(['id', 'motivo', 'fecha_inicio', 'fecha_fin']),
        ];
    }
}