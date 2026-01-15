<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Firebird\Users;

class SueldoHistorial extends Model
{
    protected $table = 'sueldos_historial';

    protected $fillable = [
        'sueldo_id',
        'sueldo_diario',
        'sueldo_mensual',
        'sueldo_anual',
        'comentarios',
    ];

    protected $casts = [
        'sueldo_diario' => 'decimal:2',
        'sueldo_mensual' => 'decimal:2',
        'sueldo_anual' => 'decimal:2',
    ];

    /**
     * Relación con Sueldo
     */
    public function sueldo(): BelongsTo
    {
        return $this->belongsTo(Sueldo::class, 'sueldo_id');
    }
}