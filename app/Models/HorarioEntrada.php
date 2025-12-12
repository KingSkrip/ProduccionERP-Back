<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioEntrada extends Model
{
    protected $table = 'horarios';

    protected $fillable = [
        'user_id',
        'hora_entrada',
        'hora_salida',
        'comentarios',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}