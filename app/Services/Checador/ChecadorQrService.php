<?php

namespace App\Services\Checador;

use App\Models\ChecadorAccessQrCode;
use App\Models\ChecadorEntrada;
use App\Models\ChecadorPermiso;
use App\Models\ChecadorRegistro;
use App\Models\ChecadorSalida;
use App\Models\UserFirebirdIdentity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChecadorQrService
{
    /**
     * Obtiene el QR activo de una identidad (sin crearlo).
     */
    public function obtenerActivo(int $identityId): ?ChecadorAccessQrCode
    {
        return ChecadorAccessQrCode::where('user_firebird_identity_id', $identityId)
            ->where('activo', true)
            ->first();
    }

    /**
     * Genera (o reutiliza) el QR fijo de una identidad.
     * El token NUNCA cambia mientras el QR esté activo.
     */
    public function generar(int $identityId): ChecadorAccessQrCode
    {
        $identity = UserFirebirdIdentity::with('firebirdUser')->findOrFail($identityId);

        $payloadInicial = [
            'nombre' => $identity->firebirdUser->NOMBRE ?? null,
            // 🔜 aquí se agregan más campos con el tiempo sin tocar el token
        ];

        $qr = ChecadorAccessQrCode::obtenerOCrearParaIdentity(
            $identityId,
            $payloadInicial,
            $identity->firebird_empresa
        );

        Log::info('🎫 QR_GENERADO_O_REUTILIZADO', [
            'identity_id' => $identityId,
            'qr_id' => $qr->id,
            'token' => $qr->token,
            'creado' => $qr->wasRecentlyCreated,
        ]);

        return $qr;
    }

    /**
     * Revoca el QR actual (ej. usuario perdió su gafete/credencial).
     */
    public function revocar(int $identityId): ?ChecadorAccessQrCode
    {
        $qr = $this->obtenerActivo($identityId);

        if (!$qr) {
            return null;
        }

        $qr->update(['activo' => false]);

        Log::warning('🚫 QR_REVOCADO', ['identity_id' => $identityId, 'qr_id' => $qr->id]);

        return $qr;
    }

    /**
     * Registra una checada (entrada/salida) a partir de un token de QR.
     * Regresa un array con toda la data resultante lista para el Resource.
     *
     * @throws \RuntimeException si el token es inválido
     */
    public function registrarChecada(string $token, array $meta = []): array
    {
        $qr = ChecadorAccessQrCode::where('token', $token)
            ->where('activo', true)
            ->with(['identity.turnoActivo.turno'])
            ->first();

        if (!$qr) {
            throw new \RuntimeException('QR inválido o inactivo', 404);
        }

        $identity = $qr->identity;

        if (!$identity) {
            throw new \RuntimeException('Identidad asociada al QR no encontrada', 404);
        }

        $now = Carbon::now();
        $hoy = $now->toDateString();

        $ultimoRegistro = ChecadorRegistro::where('user_firebird_identity_id', $identity->id)
            ->where('fecha', $hoy)
            ->orderByDesc('fecha_hora')
            ->first();

        $tipo = (!$ultimoRegistro || $ultimoRegistro->tipo === 'salida') ? 'entrada' : 'salida';

        $permisoActivo = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->vigenteHoy()
            ->first();

        $turnoId = $identity->turnoActivo->turno_id ?? null;
        $horaProgramada = null;

        DB::beginTransaction();

        try {
            $registro = ChecadorRegistro::create([
                'user_firebird_identity_id' => $identity->id,
                'firebird_empresa' => $qr->firebird_empresa,
                'turno_id' => $turnoId,
                'tipo' => $tipo,
                'fecha' => $hoy,
                'hora' => $now->toTimeString(),
                'fecha_hora' => $now,
                'metodo' => 'qr',
                'ip_address' => $meta['ip'] ?? null,
                'dispositivo' => substr((string) ($meta['user_agent'] ?? ''), 0, 250),
                'valido' => true,
                'observaciones' => $permisoActivo
                    ? "Dentro de permiso #{$permisoActivo->id} ({$permisoActivo->motivo})"
                    : null,
            ]);

            if ($tipo === 'entrada') {
                ChecadorEntrada::create([
                    'checador_registro_id' => $registro->id,
                    'user_firebird_identity_id' => $identity->id,
                    'firebird_empresa' => $qr->firebird_empresa,
                    'turno_id' => $turnoId,
                    'fecha' => $hoy,
                    'hora_entrada' => $now->toTimeString(),
                    'hora_programada' => $horaProgramada,
                ]);
            } else {
                $ultimaEntrada = ChecadorEntrada::where('user_firebird_identity_id', $identity->id)
                    ->where('fecha', $hoy)
                    ->orderByDesc('hora_entrada')
                    ->first();

                ChecadorSalida::create([
                    'checador_registro_id' => $registro->id,
                    'checador_entrada_id' => $ultimaEntrada->id ?? null,
                    'user_firebird_identity_id' => $identity->id,
                    'firebird_empresa' => $qr->firebird_empresa,
                    'turno_id' => $turnoId,
                    'fecha' => $hoy,
                    'hora_salida' => $now->toTimeString(),
                    'hora_programada' => $horaProgramada,
                ]);
            }

            $qr->update(['ultima_lectura' => $now]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('💥 ERROR_REGISTRAR_CHECADA', ['token' => $token, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Error al registrar checada', 500);
        }

        Log::info('✅ CHECADA_REGISTRADA', [
            'identity_id' => $identity->id,
            'tipo' => $tipo,
            'registro_id' => $registro->id,
            'permiso_id' => $permisoActivo->id ?? null,
        ]);

        return [
            'registro' => $registro,
            'tipo' => $tipo,
            'usuario_nombre' => $qr->payload['nombre'] ?? null,
            'permiso' => $permisoActivo,
        ];
    }

    /**
     * Historial paginado de registros de una identidad.
     */
    public function historial(int $identityId, ?string $desde = null, ?string $hasta = null)
    {
        $desde ??= now()->startOfMonth()->toDateString();
        $hasta ??= now()->toDateString();

        return ChecadorRegistro::where('user_firebird_identity_id', $identityId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderByDesc('fecha_hora')
            ->paginate(50);
    }
}