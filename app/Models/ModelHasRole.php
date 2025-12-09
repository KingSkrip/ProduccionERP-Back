<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelHasRole extends Model
{
    protected $table = 'model_has_roles';

    protected $fillable = [
        'role_clave',
        'model_clave',
        'subrol_id',
        'model_type',
    ];

    /**
     * Relación con Rol
     */
    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'role_clave');
    }

    /**
     * Relación con User
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'model_clave');
    }

    /**
     * Relación con Subrol
     */
    public function subrol(): BelongsTo
    {
        return $this->belongsTo(Subrole::class, 'subrol_id');
    }
}
