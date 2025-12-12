<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TipoFaltaTipoAfectacion extends Model
{
    protected $table = 'tipo_falta_tipo_afectacion';

    protected $fillable = [
        'tipo_falta_id',
        'tipo_afectacion_id',
    ];

    /**
     * Relación con TipoFalta
     */
    public function tipoFalta(): BelongsTo
    {
        return $this->belongsTo(TipoFalta::class, 'tipo_falta_id');
    }

    /**
     * Relación con TipoAfectacion
     */
    public function tipoAfectacion(): BelongsTo
    {
        return $this->belongsTo(TipoAfectacion::class, 'tipo_afectacion_id');
    }
}
