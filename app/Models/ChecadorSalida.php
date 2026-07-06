<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecadorSalida extends Model
{
    protected $table = 'checador_salidas';

    protected $fillable = [
        'checador_registro_id',
        'checador_entrada_id',
        'user_firebird_identity_id',
        'firebird_empresa',
        'turno_id',
        'fecha',
        'hora_salida',
        'hora_programada',
        'minutos_anticipacion',
        'horas_extra',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function identidad()
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    public function turno()
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function entrada()
    {
        return $this->belongsTo(ChecadorEntrada::class, 'checador_entrada_id');
    }

    public function registro()
    {
        return $this->belongsTo(ChecadorRegistro::class, 'checador_registro_id');
    }
}