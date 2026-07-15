<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    protected $connection = 'mysql';
    protected $fillable = [
        'nombre',
        'descripcion',
        'activo',
    ];

    public function usuarios()
    {
        return $this->hasMany(UserPuesto::class);
    }
}