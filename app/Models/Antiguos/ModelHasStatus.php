<?php

namespace App\Models\Antiguos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Firebird\Users;

class ModelHasStatus extends Model
{
    protected $table = 'MODEL_HAS_STATUSES';

    protected $fillable = [
        'NOMBRE',
        'STATUS_ID',
        'USER_ID',
    ];

    /**
     * Relación con Status
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'STATUS_ID');
    }

    /**
     * Relación con User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'USER_ID');
    }
}
