<?php

namespace App\Http\Controllers\Checador;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorPermisoResource;
use App\Services\Checador\ChecadorPermisoService;
use Illuminate\Http\Request;

class ChecadorPermisoController extends Controller
{
    public function __construct(protected ChecadorPermisoService $permisoService) {}

    public function catalogo()
    {
        return response()->json($this->permisoService->catalogo());
    }

    public function solicitar(Request $request)
    {
        $data = $request->validate([
            'user_firebird_identity_id' => 'required|integer|exists:users_firebird_identities,id',
            'checador_catalogo_permiso_id' => 'required|integer|exists:checador_catalogo_permisos,id',
            'tipo' => 'nullable|in:normal,extraordinario',
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fin' => 'nullable|date_format:H:i|after:hora_inicio',
            'motivo' => 'required|string|max:255',
        ]);

        $permiso = $this->permisoService->solicitar($data);

        return (new ChecadorPermisoResource($permiso))
            ->additional(['message' => $permiso->estado === 'aprobado'
                ? 'Permiso registrado y aprobado automáticamente'
                : 'Permiso solicitado, en espera de aprobación'])
            ->response()
            ->setStatusCode(201);
    }

    public function pendientes(Request $request)
    {
        $pendientes = $this->permisoService->pendientes($request->query('firebird_empresa'));

        return ChecadorPermisoResource::collection($pendientes);
    }

    public function resolver(Request $request, int $permisoId)
    {
        $data = $request->validate([
            'estado' => 'required|in:aprobado,rechazado',
            'aprobado_por' => 'required|integer|exists:users_firebird_identities,id',
            'comentarios_aprobador' => 'nullable|string|max:500',
        ]);

        try {
            $permiso = $this->permisoService->resolver($permisoId, $data);

            return (new ChecadorPermisoResource($permiso))
                ->additional(['message' => 'Permiso ' . $data['estado']]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 500);
        }
    }

    public function historial(int $identityId)
    {
        return ChecadorPermisoResource::collection(
            $this->permisoService->historial($identityId)
        );
    }
}