<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaltasHistorial extends Model
{
    protected $table = 'faltas_historial';

    protected $fillable = [
        'user_id',
        'tipo_falta_id',
        'tipo_afectacion_id',
        'fecha',
        'comentarios',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

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