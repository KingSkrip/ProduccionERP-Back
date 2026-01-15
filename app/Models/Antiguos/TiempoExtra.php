<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Firebird\Users;

class TiempoExtra extends Model
{
    protected $table = 'tiempos_extra';

    protected $fillable = [
        'user_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'horas',
        'motivo',
    ];

    protected $casts = [
        'fecha' => 'date',
        'horas' => 'decimal:2',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}