<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    protected $fillable = [
        'user_id',
        'titulo',
        'mensaje',
        'leido',
        'fecha_envio',
    ];

    protected $casts = [
        'leido' => 'boolean',
        'fecha_envio' => 'datetime',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}