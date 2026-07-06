<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecadorCatalogoMovimiento extends Model
{
    protected $connection = 'mysql';
    protected $table = 'checador_catalogo_movimientos';

    protected $fillable = [
        'nombre',
        'clave',
        'codigo_legado',
        'cuenta_como_salida',
        'cuenta_como_entrada',
        'orden',
    ];

    protected $casts = [
        'cuenta_como_salida' => 'boolean',
        'cuenta_como_entrada' => 'boolean',
    ];

    /**
     * Registros de checador que usan este tipo de movimiento
     * (útil si más adelante agregas la FK checador_catalogo_movimiento_id
     * a checador_registros)
     */
    public function registros(): HasMany
    {
        return $this->hasMany(ChecadorRegistro::class, 'checador_catalogo_movimiento_id');
    }

    public function scopeActivos($query)
    {
        return $query->orderBy('orden');
    }

    public function scopeEntradas($query)
    {
        return $query->where('cuenta_como_entrada', true);
    }

    public function scopeSalidas($query)
    {
        return $query->where('cuenta_como_salida', true);
    }
}