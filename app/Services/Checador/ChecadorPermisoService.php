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
        $catalogo = ChecadorCatalogoPermiso::findOrFail($data['checador_catalogo_permiso_id']);

        $estadoInicial = $catalogo->requiere_aprobacion ? 'pendiente' : 'aprobado';

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
            'estado' => $estadoInicial,
        ]);

        Log::info('📝 PERMISO_SOLICITADO', [
            'permiso_id' => $permiso->id,
            'identity_id' => $identity->id,
            'catalogo' => $catalogo->clave,
            'estado_inicial' => $estadoInicial,
        ]);

        return $permiso->load('catalogo');
    }

    public function pendientes(?string $firebirdEmpresa = null)
    {
        $query = ChecadorPermiso::where('estado', 'pendiente')
            ->with(['identity.firebirdUser', 'catalogo'])
            ->orderBy('fecha_inicio');

        if ($firebirdEmpresa) {
            $query->where('firebird_empresa', $firebirdEmpresa);
        }

        return $query->paginate(20);
    }

    /**
     * @throws \RuntimeException si el permiso no existe o ya fue resuelto
     */
    public function resolver(int $permisoId, array $data): ChecadorPermiso
    {
        $permiso = ChecadorPermiso::find($permisoId);

        if (!$permiso) {
            throw new \RuntimeException('Permiso no encontrado', 404);
        }

        if ($permiso->estado !== 'pendiente') {
            throw new \RuntimeException('Este permiso ya fue resuelto anteriormente', 409);
        }

        $permiso->update([
            'estado' => $data['estado'],
            'aprobado_por' => $data['aprobado_por'],
            'fecha_resolucion' => now(),
            'comentarios_aprobador' => $data['comentarios_aprobador'] ?? null,
        ]);

        Log::info('✅ PERMISO_RESUELTO', [
            'permiso_id' => $permiso->id,
            'estado' => $data['estado'],
            'aprobado_por' => $data['aprobado_por'],
        ]);

        return $permiso->fresh()->load('catalogo', 'aprobador');
    }

    public function historial(int $identityId)
    {
        return ChecadorPermiso::where('user_firebird_identity_id', $identityId)
            ->with('catalogo')
            ->orderByDesc('fecha_inicio')
            ->paginate(20);
    }
}