<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

        'no_regresa',
        'todo_el_dia',

        'tipo_pago_tiempo',
        'minutos_ausencia',

        'fecha_reposicion',
        'hora_inicio_reposicion',
        'hora_fin_reposicion',
        'justificacion_pago_tiempo',

        'motivo',

        'permiso_origen_id',
        'tiempo_pagado',
        'fecha_tiempo_pagado',
        'pagado_en_registro_id',

        'estado',

        'estado_rh',
        'aprobado_por_rh',
        'fecha_resolucion_rh',
        'comentarios_rh',

        'estado_jefe',
        'aprobado_por_jefe',
        'fecha_resolucion_jefe',
        'comentarios_jefe',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',

        'hora_inicio' => 'datetime:H:i',
        'hora_fin' => 'datetime:H:i',

        'fecha_reposicion' => 'date',
        'hora_inicio_reposicion' => 'datetime:H:i',
        'hora_fin_reposicion' => 'datetime:H:i',

        'no_regresa' => 'boolean',
        'todo_el_dia' => 'boolean',
        'tiempo_pagado' => 'boolean',

        'fecha_tiempo_pagado' => 'datetime',
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

    public function pagos()
    {
        return $this->hasMany(ChecadorPermisoPago::class, 'checador_permiso_id');
    }

    public function getMinutosPendientesAttribute(): int
    {
        return max(0, ($this->minutos_ausencia ?? 0) - ($this->minutos_pagados ?? 0));
    }

    public function registros(): HasMany
    {
        return $this->hasMany(ChecadorRegistro::class, 'checador_permiso_id');
    }
}
