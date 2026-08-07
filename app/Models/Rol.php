<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = 'roles';

    protected $primaryKey = 'id';

    protected $connection = 'mysql';

    public $timestamps = true;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'nombre',
        'guard_name',
    ];

    /**
     * Relación con ModelHasRole
     */
    public function modelHasRoles()
    {
        return $this->hasMany(ModelHasRole::class, 'role_id');
    }
}
