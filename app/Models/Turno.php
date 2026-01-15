<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Turno extends Model
{
    protected $connection = 'mysql';
    protected $table = 'turnos';

    protected $fillable = [
        'firebird_empresa',
        'clave',
        'nombre',
        'hora_entrada',
        'hora_salida',
        'hora_inicio_comida',
        'hora_fin_comida',
        'entra_dia_anterior',
        'sale_dia_siguiente',
        'status_id',
    ];

    protected $casts = [
        'entra_dia_anterior' => 'boolean',
        'sale_dia_siguiente' => 'boolean',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function userTurnos(): HasMany
    {
        return $this->hasMany(UserTurno::class, 'turno_id');
    }

    public function turnoDias(): HasMany
    {
        return $this->hasMany(TurnoDia::class, 'turno_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    // ============================================
    // MÉTODOS DE CÁLCULO
    // ============================================

    /**
     * Contar días laborables en la semana
     */
    public function getDiasLaborablesAttribute(): int
    {
        return $this->turnoDias()
            ->where('es_laborable', true)
            ->count();
    }

    /**
     * Calcular multiplicador de falta
     * Fórmula: 6 / días_laborables
     */
    public function getMultiplicadorFaltaAttribute(): float
    {
        $diasLaborables = $this->dias_laborables;
        
        if ($diasLaborables === 0) {
            return 0; // Turno sin días laborables (eventual, etc.)
        }
        
        return round(6 / $diasLaborables, 2);
    }

    /**
     * Obtener configuración de un día específico
     */
    public function getDiaConfig(int $diaSemana): ?TurnoDia
    {
        return $this->turnoDias()
            ->where('dia_semana', $diaSemana)
            ->first();
    }

    /**
     * Obtener solo días laborables
     */
    public function diasLaborables(): HasMany
    {
        return $this->turnoDias()->where('es_laborable', true);
    }

    /**
     * Obtener días de descanso
     */
    public function diasDescanso(): HasMany
    {
        return $this->turnoDias()->where('es_descanso', true);
    }

    // ============================================
    // SCOPES
    // ============================================

    public function scopeActivo($query)
    {
        return $query->where('status_id', 1);
    }

    public function scopeEmpresa($query, string $empresa)
    {
        return $query->where('firebird_empresa', $empresa);
    }

    // ============================================
    // MÉTODOS DE UTILIDAD
    // ============================================

    /**
     * Obtener resumen del turno
     */
    public function getResumenAttribute(): array
    {
        return [
            'nombre' => $this->nombre,
            'dias_laborables' => $this->dias_laborables,
            'multiplicador_falta' => $this->multiplicador_falta,
            'horario_base' => $this->hora_entrada && $this->hora_salida 
                ? "{$this->hora_entrada} - {$this->hora_salida}" 
                : 'Variable',
        ];
    }
}