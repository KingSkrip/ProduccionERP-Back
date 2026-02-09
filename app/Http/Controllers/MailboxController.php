<?php

namespace App\Http\Controllers;

use App\Models\MailboxItem;
use App\Models\UserFirebirdIdentity;
use App\Models\WorkOrder;
use App\Models\WorkorderAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MailboxController extends Controller
{

    private function list(Request $request, string $folder, array $extraWhere = [])
    {
        Log::info('============================================');
        Log::info('INICIO list() - ' . $folder);
        Log::info('============================================');

        $localUserId = auth()->id();
        Log::info('localUserId: ' . $localUserId);

        $firebirdIdentity = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->first();
        $identityId = $firebirdIdentity?->id;

        Log::info('identityId: ' . $identityId);

        $perPage = (int) $request->query('per_page', 15);
        $page    = (int) $request->query('page', 1);

        if (!$identityId) {
            return response()->json([
                'mails' => [],
                'pagination' => [
                    'length' => 0,
                    'size' => $perPage,
                    'page' => $page,
                    'lastPage' => 1,
                    'startIndex' => 0,
                    'endIndex' => -1,
                ],
            ]);
        }

        /**
         * ✅ MENSAJES / ENVIADOS -> salen de WORKORDERS
         */
        if (in_array($folder, ['mensajes', 'enviados'], true)) {
            Log::info('Procesando folder: ' . $folder);


            $excludedWorkorderIds = MailboxItem::where('user_id', $identityId)
                ->whereIn('folder', ['spam', 'trash', 'drafts'])
                ->pluck('workorder_id')
                ->toArray();


            Log::info('Excluidos: ' . count($excludedWorkorderIds));

            // 🔥 CAMBIO CLAVE: Primero filtramos por type, LUEGO por usuario
            $q = WorkOrder::query();


            Log::info('🔥 DECISIÓN: ¿Quieres ver Tasks como mensajes? Si SÍ, comenta whereNotIn');

            if ($folder === 'enviados') {
                Log::info('Filtro ENVIADOS - de_id: ' . $identityId);
                $q->where('de_id', $identityId);
            } else {
                Log::info('Filtro MENSAJES - identityId: ' . $identityId . ', localUserId: ' . $localUserId);

                // 🔥 FIX: Agregamos paréntesis correctamente
                $q->where(function ($w) use ($identityId, $localUserId) {
                    $w->where('para_id', $identityId)
                        ->orWhere('de_id', $identityId)
                        ->orWhereHas('taskParticipants', function ($p) use ($localUserId, $identityId) {
                            // Buscar por localUserId (989) que es el real
                            $p->whereIn('user_id', array_filter([$localUserId, $identityId]));
                            // O si los task_participants usan identityId:
                            // $p->where('user_id', $identityId);
                        });
                });
            }

            if (count($excludedWorkorderIds) > 0) {
                $q->whereNotIn('id', $excludedWorkorderIds);
            }

            if (!empty($extraWhere)) {
                foreach ($extraWhere as $k => $v) {
                    // $q->whereHas('mailboxItems', function ($m) use ($localUserId, $k, $v) {
                    //     $m->where('user_id', $localUserId)->where($k, $v);
                    // });

                    $q->whereHas('mailboxItems', function ($m) use ($identityId, $k, $v) {
                        $m->where('user_id', $identityId)->where($k, $v);
                    });
                }
            }

            $q->with([
                // 'de', 'para', 'status',
                // 'taskParticipants.user', 'taskParticipants.status',
                // 'attachments',
                // 'mailboxItems' => fn($m) => $m->where('user_id', $localUserId),

                'de.firebirdUser',
                'para.firebirdUser',
                'status',
                'taskParticipants.user.firebirdUser',
                'attachments',
                'mailboxItems' => fn($m) => $m->where('user_id', $identityId),
            ])
                ->orderByDesc('id');

            Log::info('SQL FINAL: ' . $q->toSql());
            Log::info('BINDINGS: ' . json_encode($q->getBindings()));

            $testResults = $q->limit(10)->get();
            Log::info('Resultados sin paginar: ' . $testResults->count());

            if ($testResults->count() > 0) {
                Log::info('Primer resultado:', [
                    'id' => $testResults[0]->id,
                    'de_id' => $testResults[0]->de_id,
                    'para_id' => $testResults[0]->para_id,
                    'type' => $testResults[0]->type,
                ]);
            }

            $p = $q->paginate($perPage, ['*'], 'page', $page);


            Log::info('Total paginado: ' . $p->total());

            return response()->json([
                'mails' => $p->items(),
                'pagination' => [
                    'length' => $p->total(),
                    'size' => $p->perPage(),
                    'page' => $p->currentPage(),
                    'lastPage' => $p->lastPage(),
                    'startIndex' => ($p->currentPage() - 1) * $p->perPage(),
                    'endIndex' => (($p->currentPage() - 1) * $p->perPage()) + count($p->items()) - 1,
                ],
            ]);
        }

        /**
         * ✅ SPAM / ELIMINADOS / drafts -> salen de MAILBOX_ITEMS
         */
        $q = MailboxItem::query()
            ->where('user_id', $identityId)
            ->where('folder', $folder);

        foreach ($extraWhere as $k => $v) {
            $q->where($k, $v);
        }

        $q->whereHas('workorder')
            ->with([
                'workorder.de.firebirdUser',
                'workorder.para.firebirdUser',
                'workorder.status',
                'workorder.taskParticipants.user.firebirdUser',
                'workorder.attachments',
                // opcional: si quieres traer mailboxItems reales:
                'workorder.mailboxItems' => fn($m) => $m->where('user_id', $identityId),
            ])
            ->orderByDesc('id');


        $p = $q->paginate($perPage, ['*'], 'page', $page);

        $mails = collect($p->items())
            ->map(function ($mi) {
                $wo = $mi->workorder;
                if (!$wo) return null;

                $wo->mailbox_items = [[
                    'id' => $mi->id,
                    'folder' => $mi->folder,
                    'is_starred' => $mi->is_starred,
                    'is_important' => $mi->is_important,
                    'read_at' => $mi->read_at,
                    'created_at' => $mi->created_at,
                ]];

                return $wo;
            })
            ->filter()
            ->values();



        return response()->json([
            // 'mails' => $p->items(),
            'mails' => $mails,
            'pagination' => [
                'length' => $p->total(),
                'size' => $p->perPage(),
                'page' => $p->currentPage(),
                'lastPage' => $p->lastPage(),
                'startIndex' => ($p->currentPage() - 1) * $p->perPage(),
                'endIndex' => (($p->currentPage() - 1) * $p->perPage()) + count($p->items()) - 1,
            ],
        ]);
    }


    public function storeDraft(Request $request)
    {
        Log::info('============================================');
        Log::info('INICIO storeDraft()');
        Log::info('============================================');

        $localUserId = auth()->id();
        Log::info('localUserId: ' . $localUserId);

        $firebirdIdentity = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->first();
        $identityId = $firebirdIdentity?->id;

        Log::info('identityId: ' . $identityId);

        if (!$identityId) {
            Log::warning('Sin identidad firebird');
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        // =========================
        // 1) CREAR / ACTUALIZAR BORRADOR
        // =========================
        $draftId = $request->input('id');
        Log::info('draftId recibido: ' . ($draftId ?? 'null'));

        if ($draftId) {
            Log::info('Actualizando borrador existente: ' . $draftId);

            $wo = WorkOrder::query()
                ->where('id', $draftId)
                ->where('de_id', $identityId)
                ->firstOrFail();

            $wo->fill([
                'para_id'      => $request->input('para_id') ?: null,
                'status_id'    => $request->input('status_id', $wo->status_id ?? 1),
                'titulo'       => $request->input('titulo', $wo->titulo ?? '(Sin asunto)'),
                'descripcion'  => $request->input('descripcion', $wo->descripcion ?? ''),
            ]);

            $wo->save();
            Log::info('Borrador actualizado');
        } else {
            Log::info('Creando nuevo borrador');

            $wo = WorkOrder::create([
                'de_id'        => $identityId,
                'para_id'      => $request->input('para_id') ?: null,
                'status_id'    => $request->input('status_id', 1),
                'titulo'       => $request->input('titulo', '(Sin asunto)'),
                'descripcion'  => $request->input('descripcion', ''),
            ]);

            Log::info('Nuevo borrador creado con ID: ' . $wo->id);
        }

        // =========================
        // 2) MAILBOX ITEM EN DRAFTS
        // =========================
        Log::info('Creando/actualizando MailboxItem en drafts');

        MailboxItem::updateOrCreate(
            ['user_id' => $identityId, 'workorder_id' => $wo->id],
            ['folder' => 'drafts']
        );

        $mi = MailboxItem::query()
            ->where('user_id', $identityId)
            ->where('workorder_id', $wo->id)
            ->latest('id')
            ->first();

        Log::info('MailboxItem ID: ' . ($mi?->id ?? 'null'));

        // =========================
        // 3) GUARDAR PARTICIPANTS (IGUAL QUE createTask)
        // =========================
        $participantsJson = $request->input('participants', '[]');
        $participants = is_string($participantsJson)
            ? json_decode($participantsJson, true)
            : $participantsJson;

        Log::info('Participants recibidos: ' . json_encode($participants));

        if (is_array($participants) && count($participants) > 0) {
            // 👇 Eliminar participants anteriores si estás actualizando
            \App\Models\TaskParticipant::where('workorder_id', $wo->id)->delete();
            Log::info('Participants anteriores eliminados');

            foreach ($participants as $p) {
                if (empty($p['user_id'])) {
                    Log::warning('Participant sin user_id, saltando: ' . json_encode($p));
                    continue;
                }

                // 🔥 FIX: Convertir localUserId a identityId
                $participantLocalUserId = $p['user_id'];
                $participantIdentity = UserFirebirdIdentity::where('firebird_user_clave', $participantLocalUserId)->first();

                if (!$participantIdentity) {
                    Log::warning("No se encontró identityId para localUserId: {$participantLocalUserId}");
                    continue;
                }

                $participantIdentityId = $participantIdentity->id;
                Log::info("Convirtiendo participant: localUserId={$participantLocalUserId} -> identityId={$participantIdentityId}");

                \App\Models\TaskParticipant::create([
                    'workorder_id' => $wo->id,
                    'user_id'      => $participantIdentityId, // 👈 USAR identityId, NO localUserId
                    'role'         => $p['role'] ?? 'receptor',
                    'status_id'    => $p['status_id'] ?? null,
                    'comentarios'  => $p['comentarios'] ?? null,
                    'fecha_accion' => $p['fecha_accion'] ?? null,
                    'orden'        => $p['orden'] ?? null,
                ]);

                Log::info('Participant creado: user_id=' . $participantIdentityId . ', role=' . ($p['role'] ?? 'receptor'));
            }
        } else {
            Log::info('No hay participants para guardar');
        }

        // =========================
        // 4) GUARDAR ATTACHMENTS
        // =========================
        if ($request->hasFile('attachments')) {
            $files = $request->file('attachments');
            Log::info('Archivos recibidos en attachments[]: ' . count((array)$files));

            foreach ((array)$files as $file) {
                if (!$file || !$file->isValid()) {
                    Log::warning('Archivo inválido, saltando');
                    continue;
                }

                $originalName = $file->getClientOriginalName();
                $fileName = time() . '_' . uniqid() . '_' . $originalName;
                $path = $file->storeAs("workorders/{$wo->id}", $fileName, 'public');

                Log::info('Guardando attachment: ' . $originalName);

                WorkorderAttachment::create([
                    'workorder_id'  => $wo->id,
                    'disk'          => 'public',
                    'category'      => 'draft',
                    'original_name' => $originalName,
                    'file_name'     => $fileName,
                    'path'          => $path,
                    'mime_type'     => $file->getClientMimeType(),
                    'size'          => $file->getSize(),
                    'sha1'          => sha1_file($file->getRealPath()),
                ]);

                Log::info('Attachment guardado exitosamente: ' . $fileName);
            }
        } else {
            Log::info('No se recibieron archivos en attachments[]');
        }

        // =========================
        // 5) RESPUESTA (MISMO SHAPE QUE list())
        // =========================
        Log::info('Cargando relaciones del workorder');

        $wo = $wo->fresh()->load([
            'de.firebirdUser',
            'para.firebirdUser',
            'status',
            'taskParticipants.user.firebirdUser',
            'attachments',
            'mailboxItems' => fn($m) => $m->where('user_id', $identityId),
        ]);

        $wo->mailbox_items = $mi ? [[
            'id'           => $mi->id,
            'folder'       => $mi->folder,
            'is_starred'   => $mi->is_starred,
            'is_important' => $mi->is_important,
            'read_at'      => $mi->read_at,
            'created_at'   => $mi->created_at,
        ]] : [];

        Log::info('Total participants cargados: ' . $wo->taskParticipants->count());
        Log::info('Total attachments cargados: ' . $wo->attachments->count());
        Log::info('============================================');
        Log::info('FIN storeDraft() - Success');
        Log::info('============================================');

        return response()->json($wo);
    }



    // ============ LISTADOS ============
    public function general(Request $request)
    {
        return $this->list($request, 'mensajes');
    }

    public function spam(Request $request)
    {
        return $this->list($request, 'spam');
    }

    public function trash(Request $request)
    {
        return $this->list($request, 'trash');
    }

    public function drafts(Request $request)
    {
        return $this->list($request, 'drafts');
    }


    public function important(Request $request)
    {
        return $this->list($request, 'mensajes', ['is_important' => 1]);
    }

    public function starred(Request $request)
    {
        return $this->list($request, 'mensajes', ['is_starred' => 1]);
    }



    public function sent(Request $request)
    {
        return $this->list($request, 'enviados');
    }




    // ============ ACCIONES ============
    public function markRead(Request $request, int $id)
    {
        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        $item = MailboxItem::where('user_id', $identityId)->findOrFail($id);

        // OJO: tu tabla tiene read_at, no is_read
        $item->read_at = $request->boolean('is_read', true) ? now() : null;

        $item->save();
        return response()->json($item);
    }



    public function toggleStar(int $id)
    {
        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        $item = MailboxItem::where('user_id', $identityId)->findOrFail($id);
        $item->is_starred = !$item->is_starred;
        $item->save();

        return response()->json($item);
    }



    public function toggleImportant(int $id)
    {
        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        $item = MailboxItem::where('user_id', $identityId)->findOrFail($id);
        $item->is_important = !$item->is_important;
        $item->save();

        return response()->json($item);
    }








    public function move(Request $request, int $id)
    {
        $request->validate([
            'folder' => ['required', 'in:general,spam,trash,eliminados,drafts,mensajes'],
        ]);

        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        // ✅ normaliza UI -> DB
        $folder = $request->folder;
        if ($folder === 'general') $folder = 'mensajes';
        if ($folder === 'eliminados') $folder = 'trash';

        $item = MailboxItem::where('user_id', $identityId)->findOrFail($id);
        $item->folder = $folder;
        $item->save();

        return response()->json($item);
    }



    public function moveByWorkorder(Request $request, int $workorderId)
    {
        $request->validate([
            'folder' => ['required', 'in:general,spam,trash,eliminados,drafts,mensajes'],
        ]);

        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        $folder = $request->folder;
        if ($folder === 'general') $folder = 'mensajes';
        if ($folder === 'eliminados') $folder = 'trash';

        $item = MailboxItem::updateOrCreate(
            ['user_id' => $identityId, 'workorder_id' => $workorderId],
            ['folder' => $folder]
        );

        return response()->json($item);
    }










    public function markReadByWorkorder(Request $request, $workorderId)
    {
        $workorderId = (int) $workorderId;

        if ($workorderId <= 0) {
            return response()->json([
                'message' => 'ID de workorder inválido'
            ], 422);
        }
        
        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        $item = MailboxItem::updateOrCreate(
            ['user_id' => $identityId, 'workorder_id' => $workorderId],
            []
        );

        $item->read_at = $request->boolean('is_read', true) ? now() : null;
        $item->save();

        return response()->json($item);
    }


    public function toggleStarByWorkorder(int $workorderId)
    {
        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        $item = MailboxItem::updateOrCreate(
            ['user_id' => $identityId, 'workorder_id' => $workorderId],
            []
        );

        $item->is_starred = !$item->is_starred;
        $item->save();

        return response()->json($item);
    }



    public function toggleImportantByWorkorder(int $workorderId)
    {
        $localUserId = auth()->id();
        $identityId = UserFirebirdIdentity::where('firebird_user_clave', $localUserId)->value('id');

        if (!$identityId) {
            return response()->json(['message' => 'Sin identidad'], 422);
        }

        $item = MailboxItem::updateOrCreate(
            ['user_id' => $identityId, 'workorder_id' => $workorderId],
            []
        );

        $item->is_important = !$item->is_important;
        $item->save();

        return response()->json($item);
    }
}