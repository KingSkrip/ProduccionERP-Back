<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = 'ROLES';
    protected $primaryKey = 'CLAVE';
    public $timestamps = true;
    protected $fillable = ['NOMBRE', 'GUARD_NAME'];
}
