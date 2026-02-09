<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailboxItem extends Model
{
    protected $connection = 'mysql';
    protected $table = 'mailbox_items';

    protected $fillable = [
        'workorder_id',
        'user_id',
        'folder',
        'is_starred',
        'is_important',
        'read_at',
        'trashed_at',
    ];

    protected $casts = [
        'is_starred' => 'boolean',
        'is_important' => 'boolean',
        'read_at' => 'datetime',
        'trashed_at' => 'datetime',
    ];

    public function workorder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'workorder_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_id');
    }
}