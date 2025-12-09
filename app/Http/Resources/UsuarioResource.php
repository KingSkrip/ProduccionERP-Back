<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
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
            'telefono'          =>  $this->telefono,
            'status_id'         => $this->status_id,
            'departamento_id'   => $this->departamento_id,
            'direccion_id'      => $this->direccion_id,
            'photo'             => $this->photo,
            'permissions'       => $this->roles()->pluck('ROLE_CLAVE'),



        ];
    }
}
//photos/photo_100_1764942680.png
//photos/photo_100_1764942680.png