<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelHasRole extends Model
{
    protected $table = 'MODEL_HAS_ROLES';
    public $timestamps = false; // no tienes created_at/updated_at
    protected $fillable = ['ROLE_CLAVE', 'MODEL_CLAVE', 'MODEL_TYPE'];

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'ROLE_CLAVE', 'CLAVE');
    }
}
