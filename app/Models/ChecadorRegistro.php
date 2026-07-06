<?php

namespace App\Models;

use App\Models\Firebird\Users;
use Illuminate\Database\Eloquent\Model;

class ChecadorRegistro extends Model
{
    protected $table = 'checador_registros';

    protected $fillable = [
        'user_firebird_identity_id',
        'firebird_empresa',
        'turno_id',
        'tipo',
        'fecha',
        'hora',
        'fecha_hora',
        'metodo',
        'ip_address',
        'dispositivo',
        'latitud',
        'longitud',
        'valido',
        'observaciones',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_hora' => 'datetime',
        'valido' => 'boolean',
    ];

    public function identidad()
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }
}