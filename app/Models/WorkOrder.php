<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrder extends Model
{
    protected $connection = 'mysql';
    protected $table = 'workorders';

    protected $fillable = [
        'solicitante_id',
        'aprobador_id',
        'status_id',
        'titulo',
        'descripcion',
        'fecha_solicitud',
        'fecha_aprobacion',
        'fecha_cierre',
        'comentarios_aprobador',
        'comentarios_solicitante',
    ];

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'solicitante_id'
        );
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'aprobador_id'
        );
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}
