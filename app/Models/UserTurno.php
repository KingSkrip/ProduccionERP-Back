<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class UserTurno extends Model
{
    protected $connection = 'mysql';
    protected $table = 'user_turnos';

    protected $fillable = [
        'user_firebird_identity_id',
        'turno_id',
        'fecha_inicio',
        'fecha_fin',
        'semana_anio',
        'dias_descanso_personalizados',
        'status_id',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'semana_anio' => 'integer',
        'dias_descanso_personalizados' => 'array',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(
            UserFirebirdIdentity::class,
            'user_firebird_identity_id'
        );
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function esVigente(?Carbon $fecha = null): bool
    {
        $fecha = $fecha ?? today();

        $inicioValido = is_null($this->fecha_inicio) || $fecha->greaterThanOrEqualTo($this->fecha_inicio);
        $finValido = is_null($this->fecha_fin) || $fecha->lessThanOrEqualTo($this->fecha_fin);

        return $inicioValido && $finValido;
    }

    /**
     * Obtener configuración del día actual
     * Carbon ya usa 0=Domingo automáticamente
     */
    public function getDiaConfigHoy(?Carbon $fecha = null): ?TurnoDia
    {
        $fecha = $fecha ?? now();
        $diaSemana = $fecha->dayOfWeek; // 0=Domingo

        return $this->turno->getDiaConfig($diaSemana);
    }

    public function trabajaHoy(?Carbon $fecha = null): bool
    {
        $fecha = $fecha ?? now();
        $diaSemana = $fecha->dayOfWeek; // 0=Domingo

        // Si tiene días personalizados, verificar
        if (!empty($this->dias_descanso_personalizados)) {
            return !in_array($diaSemana, $this->dias_descanso_personalizados);
        }

        // Sino, usar configuración del turno
        $turnoDia = $this->getDiaConfigHoy($fecha);
        return $turnoDia && $turnoDia->es_laborable;
    }

    public function getHorariosHoy(?Carbon $fecha = null): ?array
    {
        if (!$this->trabajaHoy($fecha)) {
            return null;
        }

        $turnoDia = $this->getDiaConfigHoy($fecha);

        return [
            'hora_entrada' => $turnoDia->hora_entrada_efectiva,
            'hora_salida' => $turnoDia->hora_salida_efectiva,
            'hora_inicio_comida' => $turnoDia->hora_inicio_comida_efectiva,
            'hora_fin_comida' => $turnoDia->hora_fin_comida_efectiva,
            'sale_dia_siguiente' => $turnoDia->sale_dia_siguiente,
            'entra_dia_anterior' => $turnoDia->entra_dia_anterior,
        ];
    }

    /**
     * Obtener o calcular semana del año
     */
    public function getSemanaAnioCalculadaAttribute(): int
    {
        if ($this->semana_anio) {
            return $this->semana_anio;
        }

        // Calcular basado en fecha_inicio o hoy
        $fecha = $this->fecha_inicio ?? now();
        return $fecha->weekOfYear; // ISO-8601
    }

    public function scopeActivo($query)
    {
        return $query->where('status_id', 1);
    }

    public function scopeVigente($query, ?Carbon $fecha = null)
    {
        $fecha = $fecha ?? today();

        return $query->where(function ($q) use ($fecha) {
            $q->where(function ($subQ) use ($fecha) {
                $subQ->whereNull('fecha_inicio')
                    ->orWhereDate('fecha_inicio', '<=', $fecha);
            })->where(function ($subQ) use ($fecha) {
                $subQ->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fecha);
            });
        });
    }

    /**
     * Scope para filtrar por semana del año
     */
    public function scopeSemana($query, int $semana)
    {
        return $query->where('semana_anio', $semana);
    }
}
