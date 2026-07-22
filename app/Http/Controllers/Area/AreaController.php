<?php

namespace App\Http\Controllers\Area;

use App\Http\Controllers\Controller;
use App\Services\Areas\AreaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AreaController extends Controller
{
    public function __construct(protected AreaService $areaService)
    {
    }

    // GET /api/areas
    public function index(Request $request): JsonResponse
    {
        $areas = $this->areaService->listar(
            $request->only(['activo', 'search']),
            (int) $request->get('per_page', 20)
        );

        return response()->json($areas);
    }

    // GET /api/areas/activas  (para selects/combos, sin paginar)
    public function activas(): JsonResponse
    {
        return response()->json($this->areaService->listarActivas());
    }

    // GET /api/areas/{id}
    public function show(int $id): JsonResponse
    {
        return response()->json($this->areaService->encontrar($id));
    }

    // POST /api/areas
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:150', 'unique:areas,nombre'],
            'descripcion' => ['nullable', 'string'],
            'activo'      => ['boolean'],
        ]);

        $area = $this->areaService->crear($data);

        return response()->json($area, 201);
    }

    // PUT/PATCH /api/areas/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'nombre'      => ['sometimes', 'required', 'string', 'max:150', "unique:areas,nombre,{$id}"],
            'descripcion' => ['nullable', 'string'],
            'activo'      => ['boolean'],
        ]);

        $area = $this->areaService->actualizar($id, $data);

        return response()->json($area);
    }

    // DELETE /api/areas/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->areaService->eliminar($id);

        return response()->json(['message' => 'Área eliminada correctamente.']);
    }

    // PATCH /api/areas/{id}/toggle-activo
    public function toggleActivo(int $id): JsonResponse
    {
        return response()->json($this->areaService->alternarActivo($id));
    }
}