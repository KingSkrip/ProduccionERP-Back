<?php

namespace App\Http\Controllers;

use App\Events\WorkorderCreated;
use App\Models\TaskParticipant;
use App\Models\UserFirebirdIdentity;
use App\Models\WorkOrder;
use App\Models\WorkorderAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        if (is_string($request->input('participants'))) {
            $decoded = json_decode($request->input('participants'), true);
            $request->merge([
                'participants' => is_array($decoded) ? $decoded : []
            ]);
        }

        Log::info('STORE WORKORDER - REQUEST RAW', [
            'all' => $request->all(),
            'de_id' => $request->input('de_id'),
            'para_id' => $request->input('para_id'),
        ]);

        $userId = auth()->id();

        $firebirdIdentity = UserFirebirdIdentity::where(
            'firebird_user_clave',
            $userId
        )->first();

        Log::info('STORE WORKORDER - DEBUG FIREBIRD IDENTITY', [
            'auth_user_id' => $userId,
            'firebird_identity_found' => !is_null($firebirdIdentity),
            'firebird_user_clave' => $firebirdIdentity?->firebird_user_clave,
            'firebird_identity_id' => $firebirdIdentity?->id,
        ]);

        $request->merge([
            'de_id' => filled($request->input('de_id'))
                ? (int) $request->input('de_id')
                : null,
            'para_id' => filled($request->input('para_id'))
                ? (int) $request->input('para_id')
                : null,
        ]);

        Log::info('STORE WORKORDER - REQUEST NORMALIZED', [
            'de_id' => $request->input('de_id'),
            'para_id' => $request->input('para_id'),
        ]);

        try {
            $validated = $request->validate([
                'de_id'   => ['required', 'integer', 'exists:users_firebird_identities,id'],
                'para_id' => ['nullable', 'integer', 'exists:users_firebird_identities,id'],

                'status_id' => ['nullable', 'integer', 'exists:statuses,id'],
                'titulo'    => ['required', 'string', 'max:200'],
                'descripcion' => ['nullable', 'string'],

                'participants' => ['sometimes', 'array'],
                'participants.*' => ['required', 'array'],

                'participants.*.user_id' => ['required', 'integer', 'exists:users_firebird_identities,firebird_user_clave'],
                'participants.*.role' => ['required', 'string', 'max:50'],
                'participants.*.status_id' => ['nullable', 'integer', 'exists:statuses,id'],
                'participants.*.comentarios' => ['nullable', 'string'],
                'participants.*.fecha_accion' => ['nullable', 'date'],
                'participants.*.orden' => ['nullable', 'integer'],

                'attachments' => ['sometimes', 'array'],
                'attachments.*' => ['file', 'max:20480'],

            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('STORE WORKORDER - VALIDATION FAILED', [
                'errors' => $e->errors(),
                'input' => $request->all(),
            ]);

            throw $e;
        }

        Log::info('STORE WORKORDER - VALIDATION PASSED', $validated);

        $workorder = DB::transaction(function () use ($validated, $firebirdIdentity, $request) {

            Log::info('STORE WORKORDER - TRANSACTION START', [
                'firebird_user_clave' => $firebirdIdentity?->firebird_user_clave,
                'firebird_identity_id' => $firebirdIdentity?->id,
                'validated_de_id' => $validated['de_id'],
            ]);

            $descripcionLimpia = strip_tags($validated['descripcion'] ?? '');

            $workorder = WorkOrder::create([
                'de_id' => $firebirdIdentity?->id ?? $validated['de_id'],
                'para_id' => $validated['para_id'] ?? null,
                'status_id' => $validated['status_id'] ?? null,
                'type' => 'Task',
                'titulo' => $validated['titulo'],
                'descripcion' => $descripcionLimpia,
                'fecha_solicitud' => now(),
            ]);

            Log::info('STORE WORKORDER - WORKORDER CREATED', [
                'workorder_id' => $workorder->id,
            ]);

            $files = $request->file('attachments', []);

            foreach ($files as $file) {
                $mime = $file->getMimeType() ?? '';
                $isImage = str_starts_with($mime, 'image/');
                $category = $isImage ? 'images' : 'documentos';

                $dir = "task/{$category}";
                $original = $file->getClientOriginalName();
                $ext = $file->getClientOriginalExtension();
                $fileName = uniqid('att_', true) . ($ext ? ".{$ext}" : '');

                $path = $file->storeAs($dir, $fileName, 'workorders');

                WorkorderAttachment::create([
                    'workorder_id'   => $workorder->id,
                    'disk'           => 'workorders',
                    'category'       => $category,
                    'original_name'  => $original,
                    'file_name'      => $fileName,
                    'path'           => $path,
                    'mime_type'      => $mime,
                    'size'           => $file->getSize(),
                    'sha1'           => sha1_file($file->getRealPath()),
                ]);
            }

            Log::info('STORE WORKORDER - ATTACHMENTS INSERTED', [
                'files_count' => count($files),
            ]);

            $participants = $validated['participants'] ?? [];

            if (!empty($participants)) {
                $now = now();

                $rows = collect($participants)->map(function ($p, $i) use ($workorder, $now) {

                    $userIdentity = UserFirebirdIdentity::where('firebird_user_clave', $p['user_id'])->first();

                    if (!$userIdentity) {
                        throw new \Exception("Usuario con firebird_user_clave {$p['user_id']} no encontrado");
                    }

                    return [
                        'workorder_id' => $workorder->id,
                        'user_id'      => $userIdentity->id,
                        'role'         => $p['role'],
                        'status_id'    => 4,
                        'comentarios'  => $p['comentarios'] ?? null,
                        'fecha_accion' => $p['fecha_accion'] ?? null,
                        'orden'        => $p['orden'] ?? null,
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];
                })->all();

                TaskParticipant::insert($rows);
            }

            Log::info('STORE WORKORDER - TRANSACTION END', [
                'workorder_id' => $workorder->id,
            ]);

            return $workorder;
        });

        Log::info('STORE WORKORDER - RESPONSE LOAD');

        $workorder = $workorder->load([
            'de',
            'para',
            'status',
            'taskParticipants.user',
            'taskParticipants.status',
            'attachments',
        ]);

        // 🔥 BROADCAST A TODOS LOS INVOLUCRADOS
        $recipientIds = collect();
        
        // Destinatario principal
        if ($workorder->para_id && $workorder->para_id !== $firebirdIdentity?->id) {
            $recipientIds->push($workorder->para_id);
        }
        
        // Participants
        foreach ($workorder->taskParticipants as $participant) {
            if ($participant->user_id !== $firebirdIdentity?->id) {
                $recipientIds->push($participant->user_id);
            }
        }
        
        broadcast(new WorkorderCreated($workorder, $recipientIds->unique()->values()->toArray()));

        return response()->json($workorder, 201);
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

            'participants' => ['sometimes', 'array'],
            'participants.*.user_id' => ['required', 'integer', 'exists:users_firebird_identities,id'],
            'participants.*.role' => ['required', 'in:receptor,cc,bcc'],
            'participants.*.status_id' => ['nullable', 'integer', 'exists:statuses,id'],
            'participants.*.comentarios' => ['nullable', 'string'],
            'participants.*.fecha_accion' => ['nullable', 'date'],
            'participants.*.orden' => ['nullable', 'integer'],
        ]);

        $task = WorkOrder::query()
            ->where('type', 'task')
            ->findOrFail($id);

        DB::transaction(function () use ($task, $validated) {
            $task->fill(collect($validated)->except('participants')->toArray());
            $task->save();

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

        $task->delete();

        return response()->json(['message' => 'Task deleted']);
    }
}