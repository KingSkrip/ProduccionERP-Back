<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Firebird\Users;

class Subrole extends Model
{
    protected $table = 'subroles';
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
    public function modelHasRoles(): HasMany
    {
        return $this->hasMany(ModelHasRole::class, 'subrol_id');
    }
}
