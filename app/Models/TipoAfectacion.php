<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TipoAfectacion extends Model
{
    protected $table = 'tipo_afectaciones';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    /**
     * Relación con FaltasHistorial
     */
    public function faltasHistorial(): HasMany
    {
        return $this->hasMany(FaltasHistorial::class, 'tipo_afectacion_id');
    }

    /**
     * Relación muchos a muchos con TipoFalta
     */
    public function tiposFalta(): BelongsToMany
    {
        return $this->belongsToMany(
            TipoFalta::class,
            'tipo_falta_tipo_afectacion',
            'tipo_afectacion_id',
            'tipo_falta_id'
        )->withTimestamps();
    }
}