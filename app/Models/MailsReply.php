<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailsReply extends Model
{
    protected $table = 'mails_replies';

    protected $fillable = [
        'workorder_id',
        'user_id',
        'reply_to_id',
        'reply_type',
        'body',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function workorder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    // 👇 RELACIÓN CON ATTACHMENTS
    public function attachments(): HasMany
    {
        return $this->hasMany(WorkorderAttachment::class, 'reply_id');
    }
}