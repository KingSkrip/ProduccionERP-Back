<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TurnoDia extends Model
{
    protected $connection = 'mysql';
    protected $table = 'turno_dias';

    protected $fillable = [
        'turno_id',
        'dia_semana',
        'es_laborable',
        'es_descanso',
        'hora_entrada',
        'hora_salida',
        'hora_inicio_comida',
        'hora_fin_comida',
        'entra_dia_anterior',
        'sale_dia_siguiente',
    ];

    protected $casts = [
        'dia_semana' => 'integer',
        'es_laborable' => 'boolean',
        'es_descanso' => 'boolean',
        'entra_dia_anterior' => 'boolean',
        'sale_dia_siguiente' => 'boolean',
    ];

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    /**
     * Obtener nombre del día en español
     * 0=Domingo, 1=Lunes, ..., 6=Sábado
     */
    public function getNombreDiaAttribute(): string
    {
        $dias = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
        ];

        return $dias[$this->dia_semana] ?? 'Desconocido';
    }

    public function getHoraEntradaEfectivaAttribute(): ?string
    {
        return $this->hora_entrada ?? $this->turno->hora_entrada;
    }

    public function getHoraSalidaEfectivaAttribute(): ?string
    {
        return $this->hora_salida ?? $this->turno->hora_salida;
    }

    public function getHoraInicioComidaEfectivaAttribute(): ?string
    {
        return $this->hora_inicio_comida ?? $this->turno->hora_inicio_comida;
    }

    public function getHoraFinComidaEfectivaAttribute(): ?string
    {
        return $this->hora_fin_comida ?? $this->turno->hora_fin_comida;
    }

    /**
     * Verificar si hoy es día laborable
     * Carbon ya maneja domingo = 0
     */
    public function esHoyLaborable(): bool
    {
        $hoy = now()->dayOfWeek; // 0=Domingo
        return $this->dia_semana === $hoy && $this->es_laborable;
    }

    public function scopeLaborables($query)
    {
        return $query->where('es_laborable', true);
    }

    public function scopeDescansos($query)
    {
        return $query->where('es_descanso', true);
    }
}