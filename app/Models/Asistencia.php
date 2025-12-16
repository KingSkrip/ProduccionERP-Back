<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $fillable = [
        'user_id',
            'turno_id', 
        'fecha',
        'hora_entrada',
        'hora_salida',
        'estado',
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


    public function turno()
    {
        return $this->belongsTo(Turno::class);
    }
}
