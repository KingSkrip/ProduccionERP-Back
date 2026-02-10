<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WorkorderAttachment extends Model
{
    protected $connection = 'mysql';
    protected $table = 'workorder_attachments';

    protected $fillable = [
        'workorder_id',
        'reply_id',
        'disk',
        'category',
        'original_name',
        'file_name',
        'path',
        'mime_type',
        'size',
        'sha1',
    ];

    public function workorder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'workorder_id');
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(MailsReply::class, 'reply_id');
    }

    public function getUrlAttribute()
    {
        return $this->path
            ? Storage::disk('public')->url($this->path)
            : null;
    }
}