<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bono extends Model
{
    protected $table = 'bonos';

    protected $fillable = [
        'user_id',
        'monto',
        'concepto',
        'fecha',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}