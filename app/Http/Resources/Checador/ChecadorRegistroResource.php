<?php

namespace App\Http\Resources\Checador;

use Illuminate\Http\Resources\Json\JsonResource;

class ChecadorRegistroResource extends JsonResource
{
    /**
     * $this->resource es el array que regresa
     * ChecadorScanService::registrarChecada() / registrarChecadaManual()
     *
     * Estructura esperada:
     * [
     *   'registro'      => ChecadorRegistro,
     *   'tipo'          => 'entrada'|'salida',
     *   'usuario_nombre'=> string|null,
     *   'permiso'       => ChecadorPermiso|null,
     *   'puntualidad'   => array,
     *   'jornada'       => array|null,
     * ]
     */
    public function toArray($request)
    {
        $registro = $this->resource['registro'];
        $permiso = $this->resource['permiso'];
        $puntualidad = $this->resource['puntualidad'] ?? [];
        $jornada = $this->resource['jornada'] ?? null;
        $tipo = $this->resource['tipo'];
        $userPuesto = $this->resource['USER_PUESTO'] ?? null;

        return [
            // --- Identificación del registro ---
            'registro_id' => $registro->id,
            'identity_id' => $registro->user_firebird_identity_id,

            'usuario' => [
                'nombre' => $this->resource['usuario_nombre'],
                'foto' => $this->resource['usuario_photo'] ?? null,

                // 🔥 Ya NO usamos NOI aquí, usamos el puesto/área propios
                'puesto' => $userPuesto && $userPuesto->puesto ? [
                    'id'     => $userPuesto->puesto->id,
                    'nombre' => $userPuesto->puesto->nombre,
                ] : null,

                'area' => $userPuesto && $userPuesto->area ? [
                    'id'     => $userPuesto->area->id,
                    'nombre' => $userPuesto->area->nombre,
                ] : null,

                'jefe' => $userPuesto && $userPuesto->jefe ? [
                    'id'     => $userPuesto->jefe->id,
                    'nombre' => $userPuesto->jefe->firebirdUser->NOMBRE ?? null,
                ] : null,
            ],

            'firebird_empresa' => $registro->firebird_empresa,
            'turno_id' => $registro->turno_id,

            // --- Qué se registró ---
            'tipo' => $tipo,
            'metodo' => $registro->metodo,
            'fecha' => $registro->fecha,
            'hora' => $registro->hora,
            'fecha_hora' => $registro->fecha_hora,
            'valido' => (bool) $registro->valido,
            'observaciones' => $registro->observaciones,

            // --- Metadata de captura ---
            'ip_address' => $registro->ip_address,
            'dispositivo' => $registro->dispositivo,

            // --- Permiso vigente ---
            'en_permiso' => (bool) $permiso,
            'permiso' => $permiso?->only([
                'id',
                'checador_catalogo_permiso_id',
                'motivo',
                'fecha_inicio',
                'fecha_fin',
                'hora_inicio',
                'hora_fin',
                'estado',
            ]),

            // --- Puntualidad ---
            'puntualidad' => [
                'hora_programada' => $puntualidad['hora_programada'] ?? null,
                'minutos_retardo' => $tipo === 'entrada'
                    ? ($puntualidad['minutos_retardo'] ?? 0)
                    : null,
                'es_retardo' => $tipo === 'entrada'
                    ? ($puntualidad['es_retardo'] ?? false)
                    : null,
                'minutos_anticipacion' => $tipo === 'salida'
                    ? ($puntualidad['minutos_anticipacion'] ?? 0)
                    : null,
                'horas_extra' => $tipo === 'salida'
                    ? ($puntualidad['horas_extra'] ?? 0)
                    : null,
            ],

            // --- Jornada ---
            'jornada' => $jornada ? [
                'horas_trabajadas' => $jornada['horas_trabajadas'] ?? null,
                'horas_esperadas' => $jornada['horas_esperadas'] ?? null,
            ] : null,
        ];
    }
}