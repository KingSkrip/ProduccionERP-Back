<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Users;

class Departamento extends Model
{
    protected $table = 'departamentos';

    protected $fillable = [
        'nombre',
        'cuenta_coi',
        'clasificacion',
        'costo',
    ];

    public function users()
    {
        return $this->hasMany(Users::class, 'departamento_id');
    }
}
