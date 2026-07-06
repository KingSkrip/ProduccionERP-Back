<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecadorCatalogoPermiso extends Model
{
    protected $connection = 'mysql';
    protected $table = 'checador_catalogo_permisos';

    protected $fillable = [
        'nombre',
        'clave',
        'descripcion',
        'duracion_default_minutos',
        'requiere_aprobacion',
        'activo',
        'orden',
    ];

    protected $casts = [
        'requiere_aprobacion' => 'boolean',
        'activo' => 'boolean',
    ];

    public function permisos(): HasMany
    {
        return $this->hasMany(ChecadorPermiso::class, 'checador_catalogo_permiso_id');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true)->orderBy('orden');
    }
}