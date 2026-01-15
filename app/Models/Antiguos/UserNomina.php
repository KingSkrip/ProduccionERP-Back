<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Firebird\Users;

class UserNomina extends Model
{
    protected $table = 'user_nominas';

    protected $fillable = [
        'user_id',
        'numero_tarjeta',
        'banco',
        'clabe_interbancaria',
        'salario_base',
        'frecuencia_pago',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    /**
     * Relación con Salarios
     */
    public function salarios(): HasMany
    {
        return $this->hasMany(Salario::class, 'user_nomina_id');
    }
}
