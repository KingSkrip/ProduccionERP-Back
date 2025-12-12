<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkOrder extends Model
{
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

    // Relación: solicitante (usuario que creó la orden)
    public function solicitante()
    {
        return $this->belongsTo(Users::class, 'solicitante_id');
    }

    // Relación: aprobador (jefe directo)
    public function aprobador()
    {
        return $this->belongsTo(Users::class, 'aprobador_id');
    }

    // Relación: status actual
    public function status()
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}
