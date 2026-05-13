<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Cita extends Model
{
    use HasFactory;

    protected $table = 'citas';

    // Desactiva el manejo automático de timestamps
    public $timestamps = false;

    protected $fillable = [
        'id_user',
        'id_visitante',
        'cita_type_id',
        'nombre_visitante',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'motivo',
        'estado',
        'notas',
        'con_vehiculo',
        'sala',
        'asistencia',
        'recordatorio_30min',
        'recordatorio_60min',
        'recordatorio_pendiente_dia_anterior', 
        'recordatorio_pendiente_mismo_dia',
        'created_at',
    ];

    protected $casts = [
        'fecha'      => 'date:Y-m-d',
        'hora_inicio' => 'datetime:H:i',
        'hora_fin'   => 'datetime:H:i',
        'created_at' => 'datetime',
        'recordatorio_30min' => 'boolean',
        'recordatorio_60min' => 'boolean',
        'recordatorio_pendiente_dia_anterior' => 'boolean',
        'recordatorio_pendiente_mismo_dia'   => 'boolean',
    ];

    /* =========================
     | 🔗 RELACIONES
     ========================= */

    public function usuario()
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'id_user');
    }

    public function visitante()
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'id_visitante');
    }

    /* =========================
     | ⚙️ SCOPES
     ========================= */

    public function scopePendientes($query)
    {
        return $query->where('estado', 'pendiente');
    }

    public function scopeHoy($query)
    {
        return $query->whereDate('fecha', Carbon::today());
    }

    /* =========================
     | 🧠 HELPERS
     ========================= */

    public function esHoy()
    {
        return $this->fecha->isToday();
    }

    public function estaActiva()
    {
        return $this->estado === 'confirmada';
    }

    public function rangoHoras()
    {
        return $this->hora_inicio . ' - ' . $this->hora_fin;
    }

    public function tipo()
    {
        return $this->belongsTo(CitaType::class, 'cita_type_id');
    }
}