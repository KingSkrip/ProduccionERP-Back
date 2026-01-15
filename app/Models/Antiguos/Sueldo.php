<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Firebird\Users;

class Sueldo extends Model
{
    protected $table = 'sueldos';

    protected $fillable = [
        'user_id',
        'sueldo_diario',
        'sueldo_mensual',
        'sueldo_anual',
    ];

    protected $casts = [
        'sueldo_diario' => 'decimal:2',
        'sueldo_mensual' => 'decimal:2',
        'sueldo_anual' => 'decimal:2',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    /**
     * Relación con SueldoHistorial
     */
    public function historial(): HasMany
    {
        return $this->hasMany(SueldoHistorial::class, 'sueldo_id');
    }
}