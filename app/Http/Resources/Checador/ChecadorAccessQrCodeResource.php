<?php

namespace App\Http\Resources\Checador;

use Illuminate\Http\Resources\Json\JsonResource;

class ChecadorAccessQrCodeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'token' => $this->token,
            'activo' => $this->activo,
            'ultima_lectura' => $this->ultima_lectura,
            'nombre' => $this->payload['nombre'] ?? null,
            'creado' => $this->wasRecentlyCreated ?? false,
        ];
    }
}