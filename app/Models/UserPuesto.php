<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPuesto extends Model
{
    protected $fillable = [
        'user_firebird_identity_id',
        'puesto_id',
        'area_id',
        'jefe_id',
        'fecha_inicio',
        'fecha_fin',
        'activo',
    ];

    public function identity()
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'user_firebird_identity_id'
        );
    }

    public function puesto()
    {
        return $this->belongsTo(Puesto::class);
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function jefe()
{
    return $this->belongsTo(UserFirebirdIdentity::class, 'jefe_id');
}
}