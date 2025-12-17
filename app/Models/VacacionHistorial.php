<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VacacionHistorial extends Model
{
    protected $table = 'vacacion_historials';

    protected $fillable = [
        'vacacion_id',
        'fecha_inicio',
        'fecha_fin',
        'dias',
        'comentarios',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'dias' => 'integer',
    ];

    /**
     * Relación con Vacacion
     */
    public function vacacion(): BelongsTo
    {
        return $this->belongsTo(Vacacion::class, 'vacacion_id');
    }

    
}