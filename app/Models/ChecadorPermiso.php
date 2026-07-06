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
        'aprobado_por',
        'fecha_resolucion',
        'comentarios_aprobador',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_resolucion' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(ChecadorCatalogoPermiso::class, 'checador_catalogo_permiso_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'aprobado_por');
    }

    public function scopeVigenteHoy($query)
    {
        return $query->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', now()->toDateString())
            ->whereDate('fecha_fin', '>=', now()->toDateString());
    }
}