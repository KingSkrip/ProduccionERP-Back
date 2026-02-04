<?php

namespace App\Http\Controllers;

use App\Models\TaskParticipant;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $tasks = WorkOrder::query()
            ->where('type', 'task')
            ->with([
                'de',
                'para',
                'status',
                'taskParticipants.user',
                'taskParticipants.status',
            ])
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($tasks);
    }

    /**
     * Show the form for creating a new resource.
     * (En API normalmente no se usa)
     */
    public function create()
    {
        return response()->json(['message' => 'Not implemented'], 405);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'de_id' => ['required', 'integer', 'exists:users_firebird_identities,id'],
            'para_id' => ['nullable', 'integer', 'exists:users_firebird_identities,id'],
            'status_id' => ['nullable', 'integer', 'exists:statuses,id'],

            'titulo' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string'],

            // participantes (pivote)
            'participants' => ['nullable', 'array'],
            'participants.*.user_id' => ['required', 'integer', 'exists:users_firebird_identities,id'],
            'participants.*.role' => ['required', 'string', 'max:50'], // approver|assignee|watcher|reviewer
            'participants.*.status_id' => ['nullable', 'integer', 'exists:statuses,id'],
            'participants.*.comentarios' => ['nullable', 'string'],
            'participants.*.fecha_accion' => ['nullable', 'date'],
            'participants.*.orden' => ['nullable', 'integer'],
        ]);

        $workorder = DB::transaction(function () use ($validated) {
            $workorder = WorkOrder::create([
                'de_id' => $validated['de_id'],
                'para_id' => $validated['para_id'] ?? null,
                'status_id' => $validated['status_id'] ?? null,
                'type' => 'task',

                'titulo' => $validated['titulo'],
                'descripcion' => $validated['descripcion'] ?? null,

                'fecha_solicitud' => now(),
            ]);

            $participants = $validated['participants'] ?? [];

            if (!empty($participants)) {
                $rows = [];
                $now = now();

                foreach ($participants as $p) {
                    $rows[] = [
                        'workorder_id' => $workorder->id,
                        'user_id' => $p['user_id'],
                        'role' => $p['role'],
                        'status_id' => $p['status_id'] ?? null,
                        'comentarios' => $p['comentarios'] ?? null,
                        'fecha_accion' => $p['fecha_accion'] ?? null,
                        'orden' => $p['orden'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                TaskParticipant::insert($rows);
            }

            return $workorder;
        });

        return response()->json(
            $workorder->load([
                'de',
                'para',
                'status',
                'taskParticipants.user',
                'taskParticipants.status',
            ]),
            201
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $task = WorkOrder::query()
            ->where('type', 'task')
            ->with([
                'de',
                'para',
                'status',
                'taskParticipants.user',
                'taskParticipants.status',
            ])
            ->findOrFail($id);

        return response()->json($task);
    }

    /**
     * Show the form for editing the specified resource.
     * (En API normalmente no se usa)
     */
    public function edit(string $id)
    {
        return response()->json(['message' => 'Not implemented'], 405);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'de_id' => ['sometimes', 'integer', 'exists:users_firebird_identities,id'],
            'para_id' => ['sometimes', 'nullable', 'integer', 'exists:users_firebird_identities,id'],
            'status_id' => ['sometimes', 'nullable', 'integer', 'exists:statuses,id'],

            'titulo' => ['sometimes', 'string', 'max:200'],
            'descripcion' => ['sometimes', 'nullable', 'string'],

            'fecha_aprobacion' => ['sometimes', 'nullable', 'date'],
            'fecha_cierre' => ['sometimes', 'nullable', 'date'],

            'comentarios_aprobador' => ['sometimes', 'nullable', 'string'],
            'comentarios_solicitante' => ['sometimes', 'nullable', 'string'],

            // si mandas participants, se reemplazan todos
            'participants' => ['sometimes', 'array'],
            'participants.*.user_id' => ['required', 'integer', 'exists:users_firebird_identities,id'],
            'participants.*.role' => ['required', 'string', 'max:50'],
            'participants.*.status_id' => ['nullable', 'integer', 'exists:statuses,id'],
            'participants.*.comentarios' => ['nullable', 'string'],
            'participants.*.fecha_accion' => ['nullable', 'date'],
            'participants.*.orden' => ['nullable', 'integer'],
        ]);

        $task = WorkOrder::query()
            ->where('type', 'task')
            ->findOrFail($id);

        DB::transaction(function () use ($task, $validated) {
            // update workorder (solo los campos presentes)
            $task->fill(collect($validated)->except('participants')->toArray());
            $task->save();

            // si vienen participants, los reemplazamos
            if (array_key_exists('participants', $validated)) {
                TaskParticipant::where('workorder_id', $task->id)->delete();

                $participants = $validated['participants'] ?? [];
                if (!empty($participants)) {
                    $rows = [];
                    $now = now();

                    foreach ($participants as $p) {
                        $rows[] = [
                            'workorder_id' => $task->id,
                            'user_id' => $p['user_id'],
                            'role' => $p['role'],
                            'status_id' => $p['status_id'] ?? null,
                            'comentarios' => $p['comentarios'] ?? null,
                            'fecha_accion' => $p['fecha_accion'] ?? null,
                            'orden' => $p['orden'] ?? null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    TaskParticipant::insert($rows);
                }
            }
        });

        return response()->json(
            $task->fresh()->load([
                'de',
                'para',
                'status',
                'taskParticipants.user',
                'taskParticipants.status',
            ])
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $task = WorkOrder::query()
            ->where('type', 'task')
            ->findOrFail($id);

        // cascade borrará task_participants por FK onDelete('cascade')
        $task->delete();

        return response()->json(['message' => 'Task deleted']);
    }
}