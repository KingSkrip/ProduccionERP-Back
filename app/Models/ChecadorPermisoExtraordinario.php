<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecadorPermisoExtraordinario extends Model
{
    protected $table = 'checador_permisos_extraordinarios';

    protected $fillable = [
        'user_firebird_identity_id',
        'puede_salir_cualquier_momento',
        'salir_cualquier_momento_necesita_permiso',
        'puede_salir_comer',
        'salir_comer_necesita_permiso',
        'puede_entrar_tarde',
        'tolerancia_ilimitada',
        'tolerancia_minutos',
        'hora_limite',
        'permiso_extraordinario_otro',
        'activo',
    ];

    protected $casts = [
        'puede_salir_cualquier_momento'            => 'boolean',
        'salir_cualquier_momento_necesita_permiso' => 'boolean',
        'puede_salir_comer'                        => 'boolean',
        'salir_comer_necesita_permiso'              => 'boolean',
        'puede_entrar_tarde'                        => 'boolean',
        'tolerancia_ilimitada'                      => 'boolean',
        'tolerancia_minutos'                        => 'integer',
        'hora_limite'                               => 'datetime:H:i',
        'activo'                                    => 'boolean',
    ];

    /**
     * El trabajador dueño de este permiso extraordinario.
     */
    public function userFirebirdIdentity(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    /**
     * ¿Este trabajador puede checar entrada tarde ahora mismo,
     * dado un límite de tolerancia? Útil para el ChecadorScanService.
     */
    public function dentroDeTolerancia(\DateTimeInterface $horaChecada): bool
    {
        if (! $this->puede_entrar_tarde) {
            return false;
        }

        if ($this->tolerancia_ilimitada) {
            return true;
        }

        if (! $this->hora_limite) {
            return false;
        }

        // hora_limite ya viene casteada como Carbon (H:i)
        return $horaChecada->format('H:i') <= $this->hora_limite->format('H:i');
    }
}