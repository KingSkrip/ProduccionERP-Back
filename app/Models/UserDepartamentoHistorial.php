<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDepartamentoHistorial extends Model
{
    protected $table = 'user_departamento_historial';

    protected $fillable = [
        'user_id',
        'departamento_id',
        'fecha_inicio',
        'fecha_fin',
        'comentarios',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }

    /**
     * Relación con Departamento
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }
}