<?php

namespace App\Services\Areas;

use App\Models\Area;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AreaService
{
    public function listar(array $filtros = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Area::query();

        if (array_key_exists('activo', $filtros) && $filtros['activo'] !== null) {
            $query->where('activo', (bool) $filtros['activo']);
        }

        if (!empty($filtros['search'])) {
            $query->where('nombre', 'like', "%{$filtros['search']}%");
        }

        return $query->orderBy('nombre')->paginate($perPage);
    }

    /**
     * Todas las áreas activas, sin paginar (útil para selects/combos).
     */
    public function listarActivas(): Collection
    {
        return Area::where('activo', true)->orderBy('nombre')->get();
    }

    public function encontrar(int $id): Area
    {
        return Area::findOrFail($id);
    }

    public function crear(array $data): Area
    {
        return Area::create([
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'activo'      => $data['activo'] ?? true,
        ]);
    }

    public function actualizar(int $id, array $data): Area
    {
        $area = Area::findOrFail($id);
        $area->update($data);
        return $area;
    }

    public function eliminar(int $id): bool
    {
        $area = Area::findOrFail($id);
        return (bool) $area->delete();
    }

    /**
     * Alterna el estado activo/inactivo sin necesidad de mandar el payload completo.
     */
    public function alternarActivo(int $id): Area
    {
        $area = Area::findOrFail($id);
        $area->activo = !$area->activo;
        $area->save();
        return $area;
    }
}