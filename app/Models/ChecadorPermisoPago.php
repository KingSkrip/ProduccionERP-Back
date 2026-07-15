<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecadorPermisoPago extends Model
{
    protected $fillable = [
        'checador_permiso_id',
        'checador_registro_id',
        'user_firebird_identity_id',
        'origen',
        'minutos_abonados',
        'fecha',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function permiso()
    {
        return $this->belongsTo(ChecadorPermiso::class, 'checador_permiso_id');
    }

    public function registro()
    {
        return $this->belongsTo(ChecadorRegistro::class, 'checador_registro_id');
    }

    public function identity()
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }
}