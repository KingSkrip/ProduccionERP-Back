<?php

namespace App\Models;

use App\Models\Firebird\Users;
use Illuminate\Database\Eloquent\Model;

class ChecadorEntrada extends Model
{
    protected $table = 'checador_entradas';

    protected $fillable = [
        'checador_registro_id',
        'user_firebird_identity_id',
        'firebird_empresa',
        'turno_id',
        'fecha',
        'hora_entrada',
        'hora_programada',
        'minutos_retardo',
        'es_retardo',
    ];

    protected $casts = [
        'fecha' => 'date',
        'es_retardo' => 'boolean',
    ];

    public function identidad()
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function registro()
    {
        return $this->belongsTo(ChecadorRegistro::class, 'checador_registro_id');
    }

    public function salida()
    {
        return $this->hasOne(ChecadorSalida::class, 'checador_entrada_id');
    }
}