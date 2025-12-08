<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            // Datos básicos
            'id'            => $this->CLAVE,
            'name'          => $this->NOMBRE,
            'email'         => $this->CORREO,
            'usuario'       => $this->USUARIO,
            'perfil'        => $this->PERFIL,
            'depto'         => $this->DEPTO,
            'departamento'  => $this->DEPARTAMENTO,
            'almacen'       => $this->ALMACEN,
            'cve_alm'       => $this->CVE_ALM,
            'status'        => $this->STATUS,

            // Permisos
            'permissions'   => $this->roles()->pluck('ROLE_CLAVE'),

            // Campos adicionales útiles
            'photo'         => $this->PHOTO,
            'scale'         => $this->SCALE,
            'desktop'       => $this->DESKTOP,
            'ctrlses'       => $this->CTRLSES,
            'printrep'      => $this->PRINTREP,
            'printlbl'      => $this->PRINTLBL,
            'reimprpt'      => $this->REIMPRPT,
            'av'            => $this->AV,
            'ac'            => $this->AC,
            'ad'            => $this->AD,
            'ae'            => $this->AE,
            'cve_agt'       => $this->CVE_AGT,
            'version'       => $this->VERSION,
            'fechaact'      => $this->FECHAACT?->format('Y-m-d H:i:s'),
            'versionrh'     => $this->VERSIONRH,
            'fechactrh'     => $this->FECHAACTRH?->format('Y-m-d H:i:s'),
            'deporth'       => $this->DEPORTH,
            'departamentorh'=> $this->DEPARTAMENTORH,

            // Todos los demás campos (opcional: solo si quieres TODO)
            // 'raw' => $this->resource->toArray(),
        ];
    }
}
//photos/photo_100_1764942680.png
//photos/photo_100_1764942680.png