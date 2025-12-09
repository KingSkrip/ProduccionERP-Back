<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Users extends Authenticatable
{
    use Notifiable;

    protected $table = 'users'; // coincide con la migración
    protected $primaryKey = 'id'; // ajustado a la PK de la migración
    public $timestamps = true;

    protected $fillable = [
        'nombre',
        'usuario',
        'curp',
        'telefono',
        'correo',
        'password',
        'status_id',
        'departamento_id',
        'direccion_id',
        'photo',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con Status
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    /**
     * Relación con Departamento
     */
    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    /**
     * Relación con Direccion
     */
    public function direccion(): BelongsTo
    {
        return $this->belongsTo(Direccion::class, 'direccion_id');
    }

    /**
     * Roles asignados al usuario
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ModelHasRole::class, 'model_clave');
    }

    /**
     * Status asignados al usuario
     */
    public function modelHasStatuses(): HasMany
    {
        return $this->hasMany(ModelHasStatus::class, 'user_id');
    }

    /**
     * Nómina
     */
    public function nomina(): HasMany
    {
        return $this->hasMany(UserNomina::class, 'user_id');
    }

    /**
     * Empleos
     */
    public function empleos(): HasMany
    {
        return $this->hasMany(UserEmpleo::class, 'user_id');
    }

    /**
     * Datos fiscales
     */
    public function fiscal(): HasMany
    {
        return $this->hasMany(UserFiscal::class, 'user_id');
    }

    /**
     * Seguridad social
     */
    public function seguridadSocial(): HasMany
    {
        return $this->hasMany(UserSeguridadSocial::class, 'user_id');
    }
}
