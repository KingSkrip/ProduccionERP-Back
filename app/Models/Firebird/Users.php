<?php

namespace App\Models\Firebird;


use App\Models\DepSupervisor;
use App\Models\FaltasHistorial;
use App\Models\HorarioEntrada;
use App\Models\Notificacion;
use App\Models\PasswordReset;
use App\Models\Status;
use App\Models\TiempoExtra;
use App\Models\UserDepartamentoHistorial;
use App\Models\Vacacion;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Users extends Authenticatable
{
    use Notifiable;

    protected $connection = 'firebird';
    protected $table = 'USUARIOS';
    protected $primaryKey = 'ID';
        // protected $primaryKey = 'CLAVE';
    public $timestamps = false;

    public $incrementing = true;
    protected $keyType = 'int';


    // Columnas rellenables (coinciden con la tabla)
    protected $fillable = [
        'ID',
        'NOMBRE',
        'USUARIO',
        'PASSWORD',
        'PASSWORD2',
        'CORREO',
        'PERFIL',
        'SESIONES',
        'VERSION',
        'FECHAACT',
        'DEPTO',
        'DEPARTAMENTO',
        'PRINTREP',
        'PRINTLBL',
        'STATUS',
        'SCALE',
        'DEPTORH',
        'DEPARTAMENTORH',
        'VERSIONRH',
        'FECHAACTRH',
        'CVE_ALM',
        'ALMACEN',
        'AV',
        'AC',
        'AD',
        'AE',
        'CVE_AGT',
        'CTRLSES',
        'DESKTOP',
        'VE',
        'REIMPRPT',
        'PHOTO',
    ];

    protected $hidden = [
        'PASSWORD',
        'PASSWORD2',
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
        return $this->belongsTo(AntiguosDepartamento::class, 'departamento_id');
    }

    /**
     * Relación con Direccion
     */
    public function direccion()
    {
        return $this->belongsTo(AntiguosDireccion::class, 'direccion_id');
    }



    public function setPassword2Attribute($value)
    {
        $this->attributes['PASSWORD2'] = bcrypt($value);
    }


    /**
     * Status asignados al usuario
     */
    public function modelHasStatuses(): HasMany
    {
        return $this->hasMany(AntiguosModelHasStatus::class, 'USER_ID');
    }

    /**
     * Nómina
     */
    public function nomina(): HasMany
    {
        return $this->hasMany(AntiguosUserNomina::class, 'user_id');
    }

    /**
     * Empleos
     */
    public function empleos(): HasMany
    {
        return $this->hasMany(AntiguosUserEmpleo::class, 'user_id');
    }

    /**
     * Datos fiscales
     */
    public function fiscal(): HasMany
    {
        return $this->hasMany(AntiguosUserFiscal::class, 'user_id');
    }

    /**
     * Seguridad social
     */
    public function seguridadSocial(): HasMany
    {
        return $this->hasMany(AntiguosUserSeguridadSocial::class, 'user_id');
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
        return $this->hasManyThrough(AntiguosVacacionHistorial::class, AntiguosVacacion::class, 'user_id', 'vacacion_id');
    }

    /**
     * Asistencias
     */
    public function asistencias(): HasMany
    {
        return $this->hasMany(AntiguosAsistencia::class, 'user_id');
    }

    /**
     * Bonos
     */
    public function bonos(): HasMany
    {
        return $this->hasMany(AntiguosBono::class, 'user_id');
    }

    /**
     * Sueldos
     */
    public function sueldos(): HasMany
    {
        return $this->hasMany(AntiguosSueldo::class, 'user_id');
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
        return $this->hasMany(PasswordReset::class, 'USUARIO_CLAVE');
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
