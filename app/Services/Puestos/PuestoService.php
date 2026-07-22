<?php

namespace App\Services\Puestos;

use App\Models\Puesto;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PuestoService
{
    public function listar(array $filtros = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Puesto::query();

        if (array_key_exists('activo', $filtros) && $filtros['activo'] !== null) {
            $query->where('activo', (bool) $filtros['activo']);
        }

        if (!empty($filtros['es_jefe_area'])) {
            $query->where('es_jefe_area', true);
        }

        if (!empty($filtros['es_gerente'])) {
            $query->where('es_gerente', true);
        }

        if (!empty($filtros['es_rh'])) {
            $query->where('es_rh', true);
        }

        if (!empty($filtros['search'])) {
            $query->where('nombre', 'like', "%{$filtros['search']}%");
        }

        return $query->orderBy('nombre')->paginate($perPage);
    }

    public function listarActivos(): Collection
    {
        return Puesto::where('activo', true)->orderBy('nombre')->get();
    }

    public function encontrar(int $id): Puesto
    {
        return Puesto::findOrFail($id);
    }

    public function crear(array $data): Puesto
    {
        return Puesto::create([
            'nombre'         => $data['nombre'],
            'descripcion'    => $data['descripcion'] ?? null,
            'es_gerente'     => $data['es_gerente'] ?? false,
            'es_jefe_area'   => $data['es_jefe_area'] ?? false,
            'es_rh'          => $data['es_rh'] ?? false,
            'es_subordinado' => $data['es_subordinado'] ?? false,
            'activo'         => $data['activo'] ?? true,
        ]);
    }

    public function actualizar(int $id, array $data): Puesto
    {
        $puesto = Puesto::findOrFail($id);
        $puesto->update($data);
        return $puesto;
    }

    public function eliminar(int $id): bool
    {
        $puesto = Puesto::findOrFail($id);
        return (bool) $puesto->delete();
    }

    public function alternarActivo(int $id): Puesto
    {
        $puesto = Puesto::findOrFail($id);
        $puesto->activo = !$puesto->activo;
        $puesto->save();
        return $puesto;
    }
}