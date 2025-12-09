<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFiscal extends Model
{
    // Tabla asociada (opcional si sigue la convención)
    protected $table = 'user_fiscals';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'user_id',
        'rfc',
        'curp',
        'regimen_fiscal',
    ];

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
