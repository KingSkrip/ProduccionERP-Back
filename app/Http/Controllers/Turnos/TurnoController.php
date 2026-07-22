<?php

namespace App\Http\Controllers\Turnos;

use App\Http\Controllers\Controller;
use App\Services\Turnos\TurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TurnoController extends Controller
{
    public function __construct(protected TurnoService $turnoService) {}

    // GET /api/turnos
    public function index(Request $request): JsonResponse
    {
        $turnos = $this->turnoService->listar(
            $request->only(['firebird_empresa', 'status_id', 'search']),
            (int) $request->get('per_page', 20)
        );

        return response()->json($turnos);
    }

    // GET /api/turnos/{id}
    public function show(int $id): JsonResponse
    {
        $turno = $this->turnoService->encontrar($id);

        return response()->json($turno);
    }

    // POST /api/turnos
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firebird_empresa'          => ['required', 'string', 'size:2'],
            'clave'                     => ['required', 'string', 'max:10'],
            'nombre'                    => ['required', 'string', 'max:50'],
            'hora_entrada'              => ['nullable', 'date_format:H:i:s'],
            'hora_salida'               => ['nullable', 'date_format:H:i:s'],
            'entra_dia_anterior'        => ['boolean'],
            'sale_dia_siguiente'        => ['boolean'],
            'status_id'                 => ['required', 'integer', 'exists:statuses,id'],

            'dias'                      => ['nullable', 'array'],
            'dias.*.dia_semana'         => ['required_with:dias', 'integer', 'between:0,6'],
            'dias.*.es_laborable'       => ['boolean'],
            'dias.*.es_descanso'        => ['boolean'],
            'dias.*.hora_entrada'       => ['nullable', 'date_format:H:i:s'],
            'dias.*.hora_salida'        => ['nullable', 'date_format:H:i:s'],
            'dias.*.entra_dia_anterior' => ['boolean'],
            'dias.*.sale_dia_siguiente' => ['boolean'],
        ]);

        $turno = $this->turnoService->crear($data);

        return response()->json($turno, 201);
    }

    // PUT/PATCH /api/turnos/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'firebird_empresa'          => ['sometimes', 'required', 'string', 'size:2'],
            'clave'                     => ['sometimes', 'required', 'string', 'max:10'],
            'nombre'                    => ['sometimes', 'required', 'string', 'max:50'],
            'hora_entrada'              => ['nullable', 'date_format:H:i:s'],
            'hora_salida'               => ['nullable', 'date_format:H:i:s'],
            'entra_dia_anterior'        => ['boolean'],
            'sale_dia_siguiente'        => ['boolean'],
            'status_id'                 => ['sometimes', 'required', 'integer', 'exists:statuses,id'],

            'dias'                      => ['nullable', 'array'],
            'dias.*.dia_semana'         => ['required_with:dias', 'integer', 'between:0,6'],
            'dias.*.es_laborable'       => ['boolean'],
            'dias.*.es_descanso'        => ['boolean'],
            'dias.*.hora_entrada'       => ['nullable', 'date_format:H:i:s'],
            'dias.*.hora_salida'        => ['nullable', 'date_format:H:i:s'],
            'dias.*.entra_dia_anterior' => ['boolean'],
            'dias.*.sale_dia_siguiente' => ['boolean'],
        ]);

        $turno = $this->turnoService->actualizar($id, $data);

        return response()->json($turno);
    }

    // DELETE /api/turnos/{id}
    public function destroy(int $id): JsonResponse
    {
        $this->turnoService->eliminar($id);

        return response()->json(['message' => 'Turno eliminado correctamente.']);
    }

    // PATCH /api/turnos/{id}/dias/{diaSemana}
    public function actualizarDia(Request $request, int $id, int $diaSemana): JsonResponse
    {
        $data = $request->validate([
            'es_laborable'       => ['boolean'],
            'es_descanso'        => ['boolean'],
            'hora_entrada'       => ['nullable', 'date_format:H:i:s'],
            'hora_salida'        => ['nullable', 'date_format:H:i:s'],
            'entra_dia_anterior' => ['boolean'],
            'sale_dia_siguiente' => ['boolean'],
        ]);

        $dia = $this->turnoService->actualizarDia($id, $diaSemana, $data);

        return response()->json($dia);
    }


public function activos(): JsonResponse
{
    return response()->json($this->turnoService->listarActivos());
}
}