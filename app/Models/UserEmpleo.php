<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserEmpleo extends Model
{
    // Tabla asociada
    protected $table = 'user_empleos';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'user_id',
        'puesto',
        'fecha_inicio',
        'fecha_fin',
        'comentarios',
    ];

    // Casts para fechas
    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
