<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLoginSession extends Model
{
    protected $table = 'user_login_sessions';

    protected $fillable = [
        'firebird_user_id',
        'firebird_identity_id',
        'jti',
        'ip_address',
        'user_agent',
        'device',
        'browser',
        'platform',
        'status',
        'login_at',
        'last_activity',
        'paused_at',
        'logout_at',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'last_activity' => 'datetime',
        'paused_at' => 'datetime',
        'logout_at' => 'datetime',
    ];

    /**
     * Sesiones activas.
     *
     * 1 = activa
     * 2 = pausada
     * 0 = cerrada
     */
    public function scopeActivas($query)
    {
        return $query->where('status', 1);
    }

    /**
     * Sesiones pausadas.
     */
    public function scopePausadas($query)
    {
        return $query->where('status', 2);
    }

    /**
     * Sesiones cerradas.
     */
    public function scopeCerradas($query)
    {
        return $query->where('status', 0);
    }

    /**
     * Cerrar sesión.
     */
    public function cerrar(): void
    {
        $this->update([
            'status' => 0,
            'logout_at' => now(),
        ]);
    }

    /**
     * Pausar sesión.
     */
    public function pausar(): void
    {
        $this->update([
            'status' => 2,
            'paused_at' => now(),
        ]);
    }

    /**
     * Reanudar sesión.
     */
    public function reanudar(): void
    {
        $this->update([
            'status' => 1,
            'paused_at' => null,
            'last_activity' => now(),
        ]);
    }

    /**
     * Actualizar última actividad.
     */
    public function heartbeat(): void
    {
        $this->update([
            'last_activity' => now(),
        ]);
    }

    /**
     * Determina si la sesión está activa.
     */
    public function estaActiva(): bool
    {
        return $this->status === 1;
    }

    /**
     * Determina si la sesión está pausada.
     */
    public function estaPausada(): bool
    {
        return $this->status === 2;
    }

    /**
     * Determina si la sesión está cerrada.
     */
    public function estaCerrada(): bool
    {
        return $this->status === 0;
    }
}
