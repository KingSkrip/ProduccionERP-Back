<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use App\Models\Firebird\Users;

class Status extends Model
{
      protected $table = 'statuses';
    protected $primaryKey = 'id';
protected $connection = 'mysql';
    public $timestamps = true;
    public $incrementing = true;
    // protected $keyType = 'int';

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
