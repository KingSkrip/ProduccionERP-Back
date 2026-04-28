<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Priority extends Model
{
    protected $connection = 'mysql';
    protected $table = 'priorities';

    protected $fillable = ['name', 'slug', 'color', 'level'];

    public function workorders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'priority_id');
    }
}