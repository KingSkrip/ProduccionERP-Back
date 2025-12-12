<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TipoFalta extends Model
{
    protected $table = 'tipos_falta';

    protected $fillable = [
        'descripcion',
    ];

    /**
     * Relación con FaltasHistorial
     */
    public function faltasHistorial(): HasMany
    {
        return $this->hasMany(faltasHistorial::class, 'tipo_falta_id');
    }

    /**
     * Relación muchos a muchos con TipoAfectacion
     */
    public function tiposAfectacion(): BelongsToMany
    {
        return $this->belongsToMany(
            TipoAfectacion::class,
            'tipo_falta_tipo_afectacion',
            'tipo_falta_id',
            'tipo_afectacion_id'
        )->withTimestamps();
    }
}