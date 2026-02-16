<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Firebird\Users;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use App\Services\FirebirdEmpresaManualService;

class UserFirebirdIdentity extends Model
{
    protected $connection = 'mysql';
    protected $table = 'users_firebird_identities';

    protected $fillable = [
        'firebird_user_clave',
        'firebird_tb_clave',
        'firebird_tb_tabla',
        'firebird_empresa',
        'firebird_clie_clave',
        'firebird_clie_tabla',
    ];

    public function roles(): HasMany
    {
        return $this->hasMany(ModelHasRole::class, 'firebird_identity_id');
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(UserTurno::class, 'user_firebird_identity_id');
    }

    public function turnoActivo(): HasOne
    {
        return $this->hasOne(UserTurno::class, 'user_firebird_identity_id')
            ->whereHas('status', fn($q) => $q->where('nombre', 'Activo'));
    }

    public function firebirdUser()
    {
        return $this->setConnection('firebird')
            ->hasOne(\App\Models\Firebird\Users::class, 'ID', 'firebird_user_clave');
    }

    public function getTbData()
    {
        if (!$this->firebird_tb_tabla || !$this->firebird_tb_clave || !$this->firebird_empresa) {
            Log::warning('getTbData - Datos incompletos', [
                'identity_id' => $this->id,
                'tb_tabla' => $this->firebird_tb_tabla,
                'tb_clave' => $this->firebird_tb_clave,
                'empresa' => $this->firebird_empresa
            ]);
            return null;
        }

        try {
            $firebirdService = new FirebirdEmpresaManualService($this->firebird_empresa, 'SRVNOI');
            $connection = $firebirdService->getConnection();

            // 🔥 FIREBIRD GUARDA CLAVE COMO STRING CON ESPACIOS A LA IZQUIERDA
            // Necesitamos formatear el valor para que coincida
            $claveFormateada = str_pad($this->firebird_tb_clave, 10, ' ', STR_PAD_LEFT);

            Log::info('getTbData - Buscando en TB', [
                'identity_id' => $this->id,
                'tb_tabla' => $this->firebird_tb_tabla,
                'tb_clave_original' => $this->firebird_tb_clave,
                'tb_clave_formateada' => $claveFormateada,
                'empresa' => $this->firebird_empresa
            ]);

            // 🔍 Buscar con CLAVE formateada (con espacios)
            $result = $connection
                ->table($this->firebird_tb_tabla)
                ->whereRaw('CLAVE = ?', [$claveFormateada])
                ->first();

            if (!$result) {
                Log::warning('getTbData - No se encontraron datos', [
                    'identity_id' => $this->id,
                    'tb_tabla' => $this->firebird_tb_tabla,
                    'tb_clave' => $this->firebird_tb_clave,
                    'tb_clave_formateada' => $claveFormateada
                ]);
                return null;
            }

            Log::info('getTbData - Datos encontrados', [
                'identity_id' => $this->id,
                'tb_tabla' => $this->firebird_tb_tabla,
                'nombre' => $result->NOMBRE ?? 'N/A',
                'telefono' => $result->TELEFONO ?? 'N/A',
                'telefono2' => $result->TELEFONO2 ?? 'N/A',
                'tiene_telefono' => !empty($result->TELEFONO ?? $result->TELEFONO2 ?? null)
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Error obteniendo datos TB', [
                'identity_id' => $this->id,
                'tb_tabla' => $this->firebird_tb_tabla,
                'tb_clave' => $this->firebird_tb_clave,
                'empresa' => $this->firebird_empresa,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getPhoneAttribute(): ?string
    {
        $tbData = $this->getTbData();

        if (!$tbData) {
            return null;
        }

        return $tbData->TELEFONO
            ?? $tbData->TELEFONO2
            ?? $tbData->CELULAR
            ?? null;
    }
}