<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Users extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'id';
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
    public function direccion()
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

    /**
     * Vacaciones
     */
    public function vacaciones(): HasMany
    {
        return $this->hasMany(Vacacion::class, 'user_id');
    }

    /**
     * Vacaciones Historial (a través de vacaciones)
     */
    public function vacacionHistorial(): HasManyThrough
    {
        return $this->hasManyThrough(VacacionHistorial::class, Vacacion::class, 'user_id', 'vacacion_id');
    }

    /**
     * Asistencias
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'user_id');
    }

    /**
     * Bonos
     */
    public function bonos(): HasMany
    {
        return $this->hasMany(Bono::class, 'user_id');
    }

    /**
     * Sueldos
     */
    public function sueldos(): HasMany
    {
        return $this->hasMany(Sueldo::class, 'user_id');
    }

    /**
     * Tiempos Extra
     */
    public function tiemposExtra(): HasMany
    {
        return $this->hasMany(TiempoExtra::class, 'user_id');
    }

    /**
     * Horarios
     */
    public function horarios(): HasMany
    {
        return $this->hasMany(HorarioEntrada::class, 'user_id');
    }

    /**
     * Faltas Historial (CORREGIDO)
     * La tabla correcta es 'faltas_historial', no 'faltas'
     */
    public function faltas(): HasMany
    {
        return $this->hasMany(FaltasHistorial::class, 'user_id');
    }

    /**
     * Departamentos Historial
     */
    public function departamentosHistorial(): HasMany
    {
        return $this->hasMany(UserDepartamentoHistorial::class, 'user_id');
    }

    /**
     * Notificaciones
     */
    public function notificaciones(): HasMany
    {
        return $this->hasMany(Notificacion::class, 'user_id');
    }



    public function passwordResets()
    {
        return $this->hasMany(PasswordReset::class, 'usuario_id');
    }


        /**
     * WorkOrders solicitadas por el usuario
     */
    public function workordersSolicitadas(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'solicitante_id');
    }

    /**
     * WorkOrders donde el usuario es aprobador
     */
    public function workordersAprobadas(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'aprobador_id');
    }



     /**
     * NUEVAS RELACIONES PARA DEPARTAMENTOS
     */

    /**
     * Departamentos donde el usuario es supervisor/jefe
     */
    public function departamentosSupervisa(): HasMany
    {
        return $this->hasMany(DepSupervisor::class, 'user_id');
    }

    /**
     * Verifica si el usuario es jefe de algún departamento
     */
    public function esJefeDeDepartamento(): bool
    {
        return $this->departamentosSupervisa()->exists();
    }

    /**
     * Obtiene los IDs de los departamentos que supervisa
     */
    public function getDepartamentosQueSupervisa()
    {
        return $this->departamentosSupervisa()->pluck('departamento_id');
    }

}
