<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CitaType extends Model
{
    use HasFactory;

    protected $table = 'citas_types';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'color',
        'icono',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    /* =========================
     | 🔗 RELACIONES
     ========================= */

    public function citas()
    {
        return $this->hasMany(Cita::class, 'cita_type_id');
    }

    /* =========================
     | ⚙️ SCOPES
     ========================= */

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /* =========================
     | 🧠 HELPERS
     ========================= */

    public function esInterna()
    {
        return $this->slug === 'junta_interna';
    }

    public function esProveedor()
    {
        return $this->slug === 'visita_proveedor';
    }
}