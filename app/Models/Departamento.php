<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Users;

class Departamento extends Model
{
    protected $table = 'departamentos';

    protected $fillable = [
        'nombre',
    ];

    public function users()
    {
        return $this->hasMany(Users::class, 'departamento_id');
    }
}
