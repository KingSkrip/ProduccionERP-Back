<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Firebird\Users;

class UserFiscal extends Model
{
    // Tabla asociada (opcional si sigue la convención)
    protected $table = 'user_fiscals';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'user_id',
        'rfc',
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
