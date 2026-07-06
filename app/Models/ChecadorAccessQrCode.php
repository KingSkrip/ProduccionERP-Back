<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChecadorAccessQrCode extends Model
{
    protected $connection = 'mysql';
    protected $table = 'checador_access_qr_codes';

    protected $fillable = [
        'user_firebird_identity_id',
        'firebird_empresa',
        'token',
        'payload',
        'activo',
        'ultima_lectura',
    ];

    protected $casts = [
        'payload' => 'encrypted:array',
        'activo' => 'boolean',
        'ultima_lectura' => 'datetime',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(UserFirebirdIdentity::class, 'user_firebird_identity_id');
    }

    public static function obtenerOCrearParaIdentity(int $identityId, array $payloadInicial = [], ?string $empresa = null): self
    {
        $qr = static::where('user_firebird_identity_id', $identityId)
            ->where('activo', true)
            ->first();

        if ($qr) {
            return $qr;
        }

        return static::create([
            'user_firebird_identity_id' => $identityId,
            'firebird_empresa' => $empresa,
            'token' => Str::random(40),
            'payload' => $payloadInicial,
            'activo' => true,
        ]);
    }
}