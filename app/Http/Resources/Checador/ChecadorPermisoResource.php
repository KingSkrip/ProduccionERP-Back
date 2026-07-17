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

            'identity' => $this->whenLoaded('identity', function () {
                // 🔥 mismo criterio que ChecadorRegistroResource: puesto/área
                // "propios" (user_puestos en MySQL), no el de Firebird/NOI.
                $userPuesto = $this->identity->relationLoaded('puestoActivo')
                    ? $this->identity->puestoActivo
                    : null;

                return [
                    'id' => $this->identity->id,
                    'nombre' => $this->identity->firebirdUser->NOMBRE ?? null,

                    'area' => $userPuesto && $userPuesto->area ? [
                        'id' => $userPuesto->area->id,
                        'nombre' => $userPuesto->area->nombre,
                    ] : null,

                    'puesto' => $userPuesto && $userPuesto->puesto ? [
                        'id' => $userPuesto->puesto->id,
                        'nombre' => $userPuesto->puesto->nombre,
                    ] : null,
                ];
            }),

            'catalogo' => $this->whenLoaded('catalogo', fn () => [
                'clave' => $this->catalogo->clave,
                'nombre' => $this->catalogo->nombre,
            ]),

            'tipo' => $this->tipo,
            'fecha_inicio' => $this->fecha_inicio,
            'fecha_fin' => $this->fecha_fin,
            'hora_inicio' => $this->hora_inicio,
            'hora_fin' => $this->hora_fin,

            // 🔥 NUEVO: bandera de día completo, ya normalizada por el back
            'no_regresa' => (bool) $this->no_regresa,
            'todo_el_dia' => (bool) $this->todo_el_dia,

            'motivo' => $this->motivo,

            // 🔥 NUEVO: sección de pago de tiempo (solo relevante cuando el
            // catálogo lo requiere — EXTRA, PERSONAL, TRAMITE, MEDICO)
            'tipo_pago_tiempo' => $this->tipo_pago_tiempo,
            'minutos_ausencia' => $this->minutos_ausencia,
            'minutos_pagados' => $this->minutos_pagados,
            'minutos_pendientes' => $this->when(
                $this->tipo_pago_tiempo !== null,
                fn () => $this->minutos_pendientes // accessor del modelo
            ),
            'tiempo_pagado' => (bool) $this->tiempo_pagado,
            'fecha_tiempo_pagado' => $this->fecha_tiempo_pagado,
            'fecha_reposicion' => $this->fecha_reposicion,
            'hora_inicio_reposicion' => $this->hora_inicio_reposicion,
            'hora_fin_reposicion' => $this->hora_fin_reposicion,
            'justificacion_pago_tiempo' => $this->justificacion_pago_tiempo,

            // 🔥 NUEVO: si este permiso fue auto-generado como reposición de otro
            'permiso_origen_id' => $this->permiso_origen_id,

            // 🔥 estado general (calculado a partir de los dos carriles)
            'estado' => $this->estado,

            // 🔥 carril RH
            'estado_rh' => $this->estado_rh,
            'aprobado_por_rh' => $this->aprobado_por_rh,
            'fecha_resolucion_rh' => $this->fecha_resolucion_rh,
            'comentarios_rh' => $this->comentarios_rh,

            // 🔥 carril jefe
            'estado_jefe' => $this->estado_jefe,
            'aprobado_por_jefe' => $this->aprobado_por_jefe,
            'fecha_resolucion_jefe' => $this->fecha_resolucion_jefe,
            'comentarios_jefe' => $this->comentarios_jefe,

            'created_at' => $this->created_at,
        ];
    }
}