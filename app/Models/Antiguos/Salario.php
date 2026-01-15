<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Firebird\Users;

class Salario extends Model
{
    protected $table = 'salarios';

    protected $fillable = [
        'user_nomina_id',
        'monto',
        'fecha_pago',
        'concepto',
    ];

    protected $casts = [
        'fecha_pago' => 'date',
        'monto' => 'decimal:2',
    ];

    /**
     * Relación con UserNomina
     */
    public function userNomina(): BelongsTo
    {
        return $this->belongsTo(UserNomina::class, 'user_nomina_id');
    }
}
