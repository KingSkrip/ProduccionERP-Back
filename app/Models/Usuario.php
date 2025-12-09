<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    use Notifiable;

    protected $table = 'USUARIOS';
    protected $primaryKey = 'CLAVE';

    public $incrementing = false;
    public $keyType = 'int';

    public $timestamps = false;
    protected $connection = 'firebird';

    protected $fillable = [
        'CLAVE',
        'NOMBRE',
        'USUARIO',
        'PASSWORD',
        'CORREO',
        'PERFIL',
        'SESIONES',
        'VERSION',
        'FECHAACT',
        'DEPTO',
        'DEPARTAMENTO',
        'PRINTREP',
        'PRINTLBL',
        'STATUS',
        'SCALE',
        'DEPORTH',
        'DEPARTAMENTORH',
        'VERSIONRH',
        'FECHAACTRH',
        'CVE_ALM',
        'ALMACEN',
        'AV',
        'AC',
        'AD',
        'AE',
        'CVE_AGT',
        'CTRLSES',
        'DESKTOP',
        'VE',
        'REIMPRPT',
        'PASSWORD2',
        'PHOTO',
    ];

    protected $hidden = [
        'PASSWORD',
        'PASSWORD2',
    ];

    protected $casts = [
        'FECHAACT' => 'datetime',
        'FECHAACTRH' => 'datetime',
    ];

    public function roles()
    {
        return $this->hasMany(ModelHasRole::class, 'MODEL_CLAVE', 'CLAVE')
            ->where('MODEL_TYPE', 'usuarios');
    }
}

