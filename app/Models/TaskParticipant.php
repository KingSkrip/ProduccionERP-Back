<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskParticipant extends Model
{
    protected $connection = 'mysql';
    protected $table = 'task_participants';

    protected $fillable = [
        'workorder_id',
        'user_id',
        'role',
        'status_id',
        'comentarios',
        'fecha_accion',
        'orden',
    ];

    public function workorder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'workorder_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}