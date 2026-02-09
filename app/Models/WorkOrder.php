<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    protected $connection = 'mysql';
    protected $table = 'workorders';

    protected $fillable = [
        'de_id',
        'para_id',
        'status_id',
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









    
}