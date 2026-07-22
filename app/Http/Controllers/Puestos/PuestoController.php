<?php

namespace App\Http\Controllers\Puestos;

use App\Http\Controllers\Controller;
use App\Services\Puestos\PuestoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PuestoController extends Controller
{
    public function __construct(protected PuestoService $puestoService)
    {
    }

    // GET /api/puestos
    public function index(Request $request): JsonResponse
    {
        $puestos = $this->puestoService->listar(
            $request->only(['activo', 'es_jefe_area', 'es_gerente', 'es_rh', 'search']),
            (int) $request->get('per_page', 20)
        );

        return response()->json($puestos);
    }

    // GET /api/puestos/activos  (para selects/combos, sin paginar)
    public function activos(): JsonResponse
    {
        return response()->json($this->puestoService->listarActivos());
    }

    // GET /api/puestos/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json($this->puestoService->encontrar($id));
    }

    // POST /api/puestos
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'         => ['required', 'string', 'max:150', 'unique:puestos,nombre'],
            'descripcion'    => ['nullable', 'string'],
            'es_gerente'     => ['boolean'],
            'es_jefe_area'   => ['boolean'],
            'es_rh'          => ['boolean'],
            'es_subordinado' => ['boolean'],
            'activo'         => ['boolean'],
        ]);

        $puesto = $this->puestoService->crear($data);

        return response()->json($puesto, 201);
    }

    // PUT/PATCH /api/puestos/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nombre'         => ['sometimes', 'required', 'string', 'max:150', "unique:puestos,nombre,{$id}"],
            'descripcion'    => ['nullable', 'string'],
            'es_gerente'     => ['boolean'],
            'es_jefe_area'   => ['boolean'],
            'es_rh'          => ['boolean'],
            'es_subordinado' => ['boolean'],
            'activo'         => ['boolean'],
        ]);

        $puesto = $this->puestoService->actualizar($id, $data);

        return response()->json($puesto);
    }

    // DELETE /api/puestos/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->puestoService->eliminar($id);

        return response()->json(['message' => 'Puesto eliminado correctamente.']);
    }

    // PATCH /api/puestos/{id}/toggle-activo
    public function toggleActivo(int $id): JsonResponse
    {
        return response()->json($this->puestoService->alternarActivo($id));
    }
}