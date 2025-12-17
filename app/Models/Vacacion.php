<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vacacion extends Model
{
    protected $table = 'vacaciones';

    protected $fillable = [
        'user_id',
        'anio',
        'dias_totales',
        'dias_disfrutados',
    ];

    protected $casts = [
        'anio' => 'integer',
        'dias_totales' => 'integer',
        'dias_disfrutados' => 'integer',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    /**
     * Relación con VacacionHistorial
     */
    public function historial(): HasMany
    {
        return $this->hasMany(VacacionHistorial::class, 'vacacion_id');
    }

    
}