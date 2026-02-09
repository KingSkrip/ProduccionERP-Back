<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Firebird\Users;
use Illuminate\Database\Eloquent\Relations\HasOne;

class UserFirebirdIdentity extends Model
{
    // 🔥 ESTO ES LO QUE FALTABA
    protected $connection = 'mysql';

    protected $table = 'users_firebird_identities';

    protected $fillable = [
        'firebird_user_clave',
        'firebird_tb_clave',
        'firebird_tb_tabla',
        'firebird_empresa',
    ];

    public function roles(): HasMany
    {
        return $this->hasMany(ModelHasRole::class, 'firebird_identity_id');
    }
    /**
     * Turnos asignados (histórico)
     */
    public function turnos(): HasMany
    {
        return $this->hasMany(UserTurno::class, 'user_firebird_identity_id');
    }

    /**
     * Turno activo
     */
    public function turnoActivo(): HasOne
    {
        return $this->hasOne(UserTurno::class, 'user_firebird_identity_id')
            ->whereHas('status', fn($q) => $q->where('nombre', 'Activo'));
    }

    public function firebirdUser()
    {
        return $this->setConnection('firebird')
            ->hasOne(\App\Models\Firebird\Users::class, 'ID', 'firebird_user_clave');
    }
}