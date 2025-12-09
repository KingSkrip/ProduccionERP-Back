<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends Model
{
    protected $table = 'statuses';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    /**
     * Relación con ModelHasStatus
     */
    public function modelHasStatuses(): HasMany
    {
        return $this->hasMany(ModelHasStatus::class, 'status_id');
    }
}
