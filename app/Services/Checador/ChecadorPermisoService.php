<?php

namespace App\Services\Checador;

use App\Models\ChecadorCatalogoPermiso;
use App\Models\ChecadorPermiso;
use App\Models\UserFirebirdIdentity;
use Illuminate\Support\Facades\Log;

class ChecadorPermisoService
{
    public function catalogo()
    {
        return ChecadorCatalogoPermiso::activos()->get();
    }

public function solicitar(array $data): ChecadorPermiso
{
    $identity = UserFirebirdIdentity::findOrFail($data['user_firebird_identity_id']);

    $catalogo = ChecadorCatalogoPermiso::findOrFail(
        $data['checador_catalogo_permiso_id']
    );

    // 🔒 Solo un permiso de COMIDA por día (por identidad)
    if ($catalogo->clave === 'COMIDA') {
        $yaTienePermisoComidaHoy = ChecadorPermiso::where('user_firebird_identity_id', $identity->id)
            ->where('checador_catalogo_permiso_id', $catalogo->id)
            ->where('estado', '!=', 'rechazado') // uno rechazado no cuenta, puede volver a pedir
            ->whereDate('fecha_inicio', $data['fecha_inicio'])
            ->exists();

        if ($yaTienePermisoComidaHoy) {
            Log::warning('🚫 PERMISO_COMIDA_DUPLICADO', [
                'identity_id' => $identity->id,
                'fecha' => $data['fecha_inicio'],
            ]);

            throw new \RuntimeException(
                'Ya tienes un permiso de comida solicitado para hoy. Solo se permite uno al día.',
                422
            );
        }
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

        // SIEMPRE inicia pendiente
        'estado' => 'solicitado',
        'estado_rh' => 'pendiente',
        'estado_jefe' => 'pendiente',
    ]);

    Log::info('📝 PERMISO_SOLICITADO', [
        'permiso_id' => $permiso->id,
        'identity_id' => $identity->id,
        'catalogo_clave' => $catalogo->clave,
        'estado_inicial' => 'pendiente',
    ]);

    return $permiso->load('catalogo');
}

    /**
     * Bandeja de RH: todo lo que espera su visto bueno.
     */
    public function pendientesRh(?string $firebirdEmpresa = null)
    {
        $query = ChecadorPermiso::where('estado_rh', 'pendiente')
            ->with([
                'identity.firebirdUser',
                'identity.puestoActivo.area',
                'identity.puestoActivo.puesto',
                'catalogo',
            ])
            ->orderBy('fecha_inicio');

        if ($firebirdEmpresa) {
            $query->where('firebird_empresa', $firebirdEmpresa);
        }

        return $query->paginate(20);
    }

    /**
     * Bandeja del jefe: ya pasó RH, falta su firma, y es gente que le
     * reporta a él (según user_puestos.jefe_id ahorita mismo).
     */
    public function pendientesJefe(int $jefeId)
    {
        return ChecadorPermiso::where('estado_rh', 'aprobado')
            ->where('estado_jefe', 'pendiente')
            ->whereHas('identity.puestoActivo', function ($q) use ($jefeId) {
                $q->where('jefe_id', $jefeId);
            })
            ->with([
                'identity.firebirdUser',
                'identity.puestoActivo.area',
                'identity.puestoActivo.puesto',
                'catalogo',
            ])
            ->orderBy('fecha_inicio')
            ->paginate(20);
    }

    /**
     * Resuelve el permiso para UN carril (rh o jefe) y recalcula el
     * estado general.
     *
     * Reglas:
     * - RH puede resolver en cuanto está pendiente.
     * - El jefe solo puede resolver si RH ya aprobó, y solo si es
     *   realmente su jefe (según user_puestos.jefe_id actual).
     * - Si cualquiera de los dos rechaza, el permiso queda rechazado.
     * - Si ambos aprueban, el permiso queda aprobado.
     *
     * @throws \RuntimeException
     */
    public function resolver(int $permisoId, string $rol, array $data): ChecadorPermiso
    {
        if (!in_array($rol, ['rh', 'jefe'], true)) {
            throw new \RuntimeException('Rol de aprobador inválido', 422);
        }

        $permiso = ChecadorPermiso::with('identity')->find($permisoId);

        if (!$permiso) {
            throw new \RuntimeException('Permiso no encontrado', 404);
        }

        if (in_array($permiso->estado, ['aprobado', 'rechazado'], true)) {
            throw new \RuntimeException('Este permiso ya fue resuelto anteriormente', 409);
        }

        if ($rol === 'rh') {
            if ($permiso->estado_rh !== 'pendiente') {
                throw new \RuntimeException('RH ya se pronunció sobre este permiso', 409);
            }

            $permiso->estado_rh = $data['estado'];
            $permiso->aprobado_por_rh = $data['aprobado_por'];
            $permiso->fecha_resolucion_rh = now();
            $permiso->comentarios_rh = $data['comentarios_aprobador'] ?? null;
        } else { // jefe
            if ($permiso->estado_rh !== 'aprobado') {
                throw new \RuntimeException('Aún no puede resolverlo el jefe: falta la aprobación de RH', 409);
            }

            if ($permiso->estado_jefe !== 'pendiente') {
                throw new \RuntimeException('El jefe ya se pronunció sobre este permiso', 409);
            }

            $jefeIdActual = $this->jefeIdDe($permiso->identity);

            if ($jefeIdActual && (int) $data['aprobado_por'] !== (int) $jefeIdActual) {
                throw new \RuntimeException('Solo el jefe asignado puede resolver este permiso', 403);
            }

            $permiso->estado_jefe = $data['estado'];
            $permiso->aprobado_por_jefe = $data['aprobado_por'];
            $permiso->fecha_resolucion_jefe = now();
            $permiso->comentarios_jefe = $data['comentarios_aprobador'] ?? null;
        }

        // Recalcular estado general a partir de ambos carriles.
        if ($permiso->estado_rh === 'rechazado' || $permiso->estado_jefe === 'rechazado') {
            $permiso->estado = 'rechazado';
        } elseif ($permiso->estado_rh === 'aprobado' && $permiso->estado_jefe === 'aprobado') {
            $permiso->estado = 'aprobado';
        } else {
            $permiso->estado = 'pendiente';
        }

        $permiso->save();

        Log::info('✅ PERMISO_RESUELTO_' . strtoupper($rol), [
            'permiso_id' => $permiso->id,
            'estado_rh' => $permiso->estado_rh,
            'estado_jefe' => $permiso->estado_jefe,
            'estado_general' => $permiso->estado,
            'resuelto_por' => $data['aprobado_por'],
        ]);

        return $permiso->fresh()->load('catalogo', 'aprobadorRh', 'aprobadorJefe');
    }

    public function historial(int $identityId)
    {
        return ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->with('catalogo')
            ->orderByDesc('fecha_inicio')
            ->paginate(20);
    }

    /**
     * Jefe actual de una identidad, según user_puestos.
     */
    private function jefeIdDe(UserFirebirdIdentity $identity): ?int
    {
        return $identity->puestoActivo()->first()?->jefe_id;
    }
}