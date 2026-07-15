<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecadorPermiso extends Model
{
    protected $connection = 'mysql';
    protected $table = 'checador_permisos';

    protected $fillable = [
        'user_firebird_identity_id',
        'checador_catalogo_permiso_id',
        'firebird_empresa',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'hora_inicio',
        'hora_fin',
        'motivo',

        'estado',

        'estado_rh',
        'aprobado_por_rh',
        'fecha_resolucion_rh',
        'comentarios_rh',

        'estado_jefe',
        'aprobado_por_jefe',
        'fecha_resolucion_jefe',
        'comentarios_jefe',



        'tiempo_pagado',
        'fecha_tiempo_pagado',
        'pagado_en_registro_id',
        'permiso_origen_id',

        'minutos_pagados',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',

        'hora_inicio' => 'datetime:H:i',
        'hora_fin' => 'datetime:H:i',

        'fecha_resolucion_rh' => 'datetime',
        'fecha_resolucion_jefe' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(ChecadorCatalogoPermiso::class, 'checador_catalogo_permiso_id');
    }



    public function scopeVigenteHoy($query)
    {
        return $query->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', now()->toDateString())
            ->whereDate('fecha_fin', '>=', now()->toDateString());
    }

    public function aprobadorRh(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'aprobado_por_rh');
    }

    public function aprobadorJefe(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'aprobado_por_jefe');
    }

    // dentro de ChecadorPermiso.php
    public function pagos()
    {
        return $this->hasMany(ChecadorPermisoPago::class, 'checador_permiso_id');
    }

    public function getMinutosPendientesAttribute(): int
    {
        return max(0, ($this->minutos_ausencia ?? 0) - ($this->minutos_pagados ?? 0));
    }
}