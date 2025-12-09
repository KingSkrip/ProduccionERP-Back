<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subrole extends Model
{
    protected $table = 'subroles';

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
