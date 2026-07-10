<?php

namespace App\Services\Checador;

use App\Models\ChecadorCatalogoPermiso;
use App\Models\ChecadorPermiso;
use App\Models\UserFirebirdIdentity;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ChecadorPermisoService
{
    public function catalogo()
    {
        return ChecadorCatalogoPermiso::activos()->get();
    }

    public function solicitar(array $data): ChecadorPermiso
    {
        $identity = UserFirebirdIdentity::findOrFail($data['user_firebird_identity_id']);
        $catalogo = ChecadorCatalogoPermiso::findOrFail($data['checador_catalogo_permiso_id']);

        if ($catalogo->clave === 'COMIDA') {
            throw new \RuntimeException('El permiso de comida se genera automáticamente al registrar tu entrada, no se solicita manualmente.', 422);
        }

        $permiso = ChecadorPermiso::create([
            'user_firebird_identity_id' => $identity->id,
            'checador_catalogo_permiso_id' => $catalogo->id,
            'firebird_empresa' => $identity->firebird_empresa,
            'tipo' => $data['tipo'] ?? 'normal',
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'hora_inicio' => $data['hora_inicio'] ?? null,
            'hora_fin' => $data['hora_fin'] ?? null,
            'motivo' => $data['motivo'],
            'estado' => 'solicitado',
            'estado_rh' => 'no_aplica',
            'estado_jefe' => 'pendiente',
        ]);

        $this->autoAprobarSiNoTieneJefe($permiso, $identity);
        return $permiso->fresh()->load('catalogo');
    }

    private function autoAprobarSiNoTieneJefe(ChecadorPermiso $permiso, UserFirebirdIdentity $identity): void
    {
        if ($this->jefeIdDe($identity)) return;

        $permiso->estado_jefe = 'aprobado';
        $permiso->fecha_resolucion_jefe = now();
        $permiso->comentarios_jefe = 'Autoaprobado: la identidad no tiene jefe asignado.';
        $permiso->estado = 'aprobado';
        $permiso->save();
    }

    public function pendientesJefe(int $jefeId)
    {
        return ChecadorPermiso::where('estado_jefe', 'pendiente')
            ->whereHas('identity.puestoActivo', function ($q) use ($jefeId) {
                $q->where('jefe_id', $jefeId);
            })
            ->with(['identity.firebirdUser', 'identity.puestoActivo.area', 'identity.puestoActivo.puesto', 'catalogo'])
            ->orderBy('fecha_inicio')
            ->paginate(20);
    }

    public function resolver(int $permisoId, string $rol, array $data): ChecadorPermiso
    {
        if ($rol !== 'jefe') throw new RuntimeException('RH ya no participa en la aprobación de permisos. Solo el jefe puede resolver.', 422);

        $permiso = ChecadorPermiso::with('identity')->find($permisoId);
        if (!$permiso) throw new RuntimeException('Permiso no encontrado', 404);
        if (in_array($permiso->estado, ['aprobado', 'rechazado'], true)) throw new RuntimeException('Este permiso ya fue resuelto anteriormente', 409);
        if ($permiso->estado_jefe !== 'pendiente') throw new RuntimeException('El jefe ya se pronunció sobre este permiso', 409);

        $jefeIdActual = $this->jefeIdDe($permiso->identity);
        if ($jefeIdActual && (int) $data['aprobado_por'] !== (int) $jefeIdActual) throw new RuntimeException('Solo el jefe asignado puede resolver este permiso', 403);

        $permiso->estado_jefe = $data['estado'];
        $permiso->aprobado_por_jefe = $data['aprobado_por'];
        $permiso->fecha_resolucion_jefe = now();
        $permiso->comentarios_jefe = $data['comentarios_aprobador'] ?? null;
        $permiso->estado = match ($permiso->estado_jefe) {
            'rechazado' => 'rechazado',
            'aprobado' => 'aprobado',
            default => 'pendiente',
        };
        $permiso->save();

        return $permiso->fresh()->load('catalogo', 'aprobadorJefe');
    }

    public function historial(int $identityId)
    {
        return ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->with('catalogo')
            ->orderByDesc('fecha_inicio')
            ->paginate(20);
    }

    private function jefeIdDe(UserFirebirdIdentity $identity): ?int
    {
        return $identity->puestoActivo()->first()?->jefe_id;
    }

    public function crearPermisoComidaAutomaticoSiAplica(
        UserFirebirdIdentity $identity,
        string $fecha,
        ?array $horariosHoy
    ): ?ChecadorPermiso {
        if (empty($horariosHoy['hora_salida'])) {
            Log::info('🍽️ COMIDA_SIN_HORA_SALIDA_TURNO', [
                'identity_id' => $identity->id,
                'horariosHoy' => $horariosHoy,
            ]);
            return null;
        }

        $yaExiste = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->whereHas('catalogo', fn($q) => $q->where('clave', 'COMIDA'))
            ->whereDate('fecha_inicio', $fecha)
            ->where('estado', '!=', 'rechazado')
            ->exists();

        if ($yaExiste) return null;

        $catalogo = ChecadorCatalogoPermiso::where('clave', 'COMIDA')->first();
        if (!$catalogo) return null;

        $permiso = ChecadorPermiso::create([
            'user_firebird_identity_id' => $identity->id,
            'checador_catalogo_permiso_id' => $catalogo->id,
            'firebird_empresa' => $identity->firebird_empresa,
            'tipo' => 'normal',
            'fecha_inicio' => $fecha,
            'fecha_fin' => $fecha,
            'hora_inicio' => null,
            'hora_fin' => null,
            'motivo' => 'Hora de comida',
            'estado' => 'aprobado',
            'estado_rh' =>  null,
            'estado_jefe' =>  null,
            'fecha_resolucion_jefe' => now(),
            'comentarios_jefe' => 'Autoaprobado: permiso de comida automático.',
        ]);

        Log::info('🍽️ PERMISO_COMIDA_AUTOGENERADO', [
            'permiso_id' => $permiso->id,
            'identity_id' => $identity->id,
            'fecha' => $fecha,
        ]);

        return $permiso->fresh();
    }
}