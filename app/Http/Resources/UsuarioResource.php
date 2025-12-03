<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->CLAVE,
            'name' => $this->NOMBRE,
            'email' => $this->CORREO,
            'usuario' => $this->USUARIO,
            'perfil' => $this->PERFIL,
            'depto' => $this->DEPTO,
            'departamento' => $this->DEPARTAMENTO,
            'almacen' => $this->ALMACEN,
            'status' => $this->STATUS,
            'permissions' => $this->roles()->pluck('ROLE_CLAVE'),
        ];
    }
}
