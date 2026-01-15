<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Firebird\Users;

class UserSeguridadSocial extends Model
{
    // Tabla asociada
    protected $table = 'user_seguridad_socials';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'user_id',
        'numero_imss',
        'fecha_alta',
        'tipo_seguro',
    ];

    // Casts para fechas si quieres trabajar con Carbon
    protected $casts = [
        'fecha_alta' => 'date',
    ];

    /**
     * Relación con el usuario
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id');
    }
}
