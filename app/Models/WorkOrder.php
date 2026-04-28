<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $connection = 'mysql';
    protected $table = 'workorders';
    protected $appends = ['participants'];

    protected $fillable = [
        'de_id',
        'para_id',
        'status_id',
        'priority_id',
        'ticket_number',
        'type',
        'titulo',
        'descripcion',
        'fecha_solicitud',
        'fecha_aprobacion',
        'fecha_cierre',
        'comentarios_aprobador',
        'comentarios_solicitante',
    ];

    public function de(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'de_id'
        );
    }

    public function para(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'para_id'
        );
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function taskParticipants(): HasMany
    {
        return $this->hasMany(TaskParticipant::class, 'workorder_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkorderAttachment::class, 'workorder_id');
    }

    public function mailboxItems(): HasMany
    {
        return $this->hasMany(\App\Models\MailboxItem::class, 'workorder_id');
    }


    public function replies(): HasMany
    {
        return $this->hasMany(MailsReply::class, 'workorder_id')
            ->with(['user.firebirdUser', 'attachments'])
            ->orderBy('sent_at', 'asc');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class, 'priority_id');
    }


    protected static function booted(): void
    {
        static::created(function (WorkOrder $wo) {
            $wo->ticket_number = 'TK-' . str_pad($wo->id, 5, '0', STR_PAD_LEFT);
            $wo->saveQuietly();
        });
    }


        public function getParticipantsAttribute()
{
    $users = collect();

    // Emisor
    if ($this->de && $this->de->firebirdUser) {
        $users->push([
            'id' => 'de-' . $this->de->id,
            'name' => $this->de->firebirdUser->NOMBRE,
            'photo_url' => $this->de->firebirdUser->FOTO ?? null,
            'type' => 'emisor',
        ]);
    }

    // Receptor principal
    if ($this->para && $this->para->firebirdUser) {
        $users->push([
            'id' => 'para-' . $this->para->id,
            'name' => $this->para->firebirdUser->NOMBRE,
            'photo_url' => $this->para->firebirdUser->FOTO ?? null,
            'type' => 'receptor',
        ]);
    }

    // Participantes (receptores extras)
    foreach ($this->taskParticipants as $p) {
        if ($p->user && $p->user->firebirdUser) {
            $users->push([
                'id' => 'tp-' . $p->user_id,
                'name' => $p->user->firebirdUser->NOMBRE,
                'photo_url' => $p->user->firebirdUser->FOTO ?? null,
                'type' => $p->role ?? 'participante',
            ]);
        }
    }

    // 🔥 eliminar duplicados por user_id
    return $users
        ->unique('id')
        ->values();
}
}