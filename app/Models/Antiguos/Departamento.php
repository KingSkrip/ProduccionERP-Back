<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use App\Models\Firebird;
use App\Models\Firebird\Users;

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
