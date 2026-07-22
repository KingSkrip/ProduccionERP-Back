<?php

namespace App\Services\Turnos;

use App\Models\Turno;
use App\Models\TurnoDia;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class TurnoService
{
    /**
     * Lista turnos paginados, con filtros opcionales.
     *
     * @param array $filtros ['firebird_empresa' => '04', 'search' => 'MAT', 'status_id' => 1]
     */
    public function listar(array $filtros = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Turno::query()->with('dias');

        if (!empty($filtros['firebird_empresa'])) {
            $query->where('firebird_empresa', $filtros['firebird_empresa']);
        }

        if (!empty($filtros['status_id'])) {
            $query->where('status_id', $filtros['status_id']);
        }

        if (!empty($filtros['search'])) {
            $search = $filtros['search'];
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('clave', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('firebird_empresa')->orderBy('nombre')->paginate($perPage);
    }

    public function encontrar(int $id): Turno
    {
        return Turno::with('dias')->findOrFail($id);
    }

    /**
     * Crea un turno junto con sus 7 días (turno_dias).
     *
     * Estructura esperada en $data:
     * [
     *   'firebird_empresa' => '04',
     *   'clave' => 'MAT',
     *   'nombre' => 'Matutino',
     *   'hora_entrada' => '06:00:00',
     *   'hora_salida' => '14:00:00',
     *   'entra_dia_anterior' => false,
     *   'sale_dia_siguiente' => false,
     *   'status_id' => 1,
     *   'dias' => [
     *       ['dia_semana' => 0, 'es_laborable' => false, 'es_descanso' => true, ...],
     *       ...
     *   ]
     * ]
     */
    public function crear(array $data): Turno
    {
        return DB::transaction(function () use ($data) {
            $dias = $data['dias'] ?? [];
            unset($data['dias']);

            $turno = Turno::create($data);

            $this->sincronizarDias($turno, $dias);

            return $turno->load('dias');
        });
    }

    public function actualizar(int $id, array $data): Turno
    {
        return DB::transaction(function () use ($id, $data) {
            $turno = Turno::findOrFail($id);

            $dias = $data['dias'] ?? null;
            unset($data['dias']);

            $turno->update($data);

            if ($dias !== null) {
                $this->sincronizarDias($turno, $dias);
            }

            return $turno->load('dias');
        });
    }

    public function eliminar(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $turno = Turno::findOrFail($id);
            // turno_dias tiene ON DELETE CASCADE a nivel de FK,
            // pero lo borramos explícito por claridad/consistencia con otros drivers.
            $turno->dias()->delete();
            return (bool) $turno->delete();
        });
    }

    /**
     * Reemplaza (upsert) los días de un turno.
     * Si un dia_semana ya existe para el turno, lo actualiza; si no, lo crea.
     */
    public function sincronizarDias(Turno $turno, array $dias): void
    {
        foreach ($dias as $dia) {
            TurnoDia::updateOrCreate(
                [
                    'turno_id'   => $turno->id,
                    'dia_semana' => $dia['dia_semana'],
                ],
                [
                    'es_laborable'       => $dia['es_laborable'] ?? false,
                    'es_descanso'        => $dia['es_descanso'] ?? !($dia['es_laborable'] ?? false),
                    'hora_entrada'       => $dia['hora_entrada'] ?? $turno->hora_entrada,
                    'hora_salida'        => $dia['hora_salida'] ?? $turno->hora_salida,
                    'entra_dia_anterior' => $dia['entra_dia_anterior'] ?? false,
                    'sale_dia_siguiente' => $dia['sale_dia_siguiente'] ?? false,
                ]
            );
        }
    }

    /**
     * Actualiza un único día de un turno (ej. PATCH /turnos/{turno}/dias/{dia_semana}).
     */
    public function actualizarDia(int $turnoId, int $diaSemana, array $data): TurnoDia
    {
        $turno = Turno::findOrFail($turnoId);

        return TurnoDia::updateOrCreate(
            ['turno_id' => $turno->id, 'dia_semana' => $diaSemana],
            $data
        );
    }


public function listarActivos()
{
    return Turno::where('status_id', 1) // ajusta según tu catálogo de status
        ->orderBy('nombre')
        ->get();
}
}