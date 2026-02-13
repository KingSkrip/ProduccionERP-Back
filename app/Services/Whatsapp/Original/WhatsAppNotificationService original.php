<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderProjects;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationServiceOrginal
{
    private $whatsappService;
    private $frontUrl;

    public function __construct()
    {
        $this->whatsappService = new UltraMSGService();

        $this->frontUrl = config('app.frontend_url');
    }

    /**
     * Enviar notificación de nueva tarea asignada
     */
    public function sendNewTaskNotification($usuario, $request, $creatorUser, $workOrder)
    {
        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl = "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($usuario->telefono)) {
            return false;
        }

        // SOLUCIÓN: Usar mb_substr() también aquí
        $descripcion = empty($request->task_content) ? 'Sin descripción' : $request->task_content;
        if (mb_strlen($descripcion, 'UTF-8') > 100) {
            $descripcion = mb_substr($descripcion, 0, 100, 'UTF-8') . '...';
        }

        $message = "*Nueva Tarea Asignada*\n\n" .
            "Prioridad: " . $this->getPriorityText($request->priority ?? 4) . "\n" .
            "Título: " . $request->title . "\n" .
            "Asignado por: " . $creatorUser->name . "\n" .
            "Fecha de entrega: " . \Carbon\Carbon::parse(trim($request->due_date, '"'))->format('d/m/Y H:i') . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        return $this->sendWhatsAppMessage(
            $usuario->telefono,
            $message,
            'workorder_' . $workOrder->id,
            'Nueva tarea asignada',
            [
                'usuario_id' => $usuario->id,
                'work_order_id' => $workOrder->id,
                'type' => 'nueva_tarea'
            ]
        );
    }

    /**
     * Enviar notificación de tarea enviada a revisión
     */
    public function sendTaskToReviewNotification($creatorUser, $workOrder, $senderUser, $documentName = null)
    {

        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl =  "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($creatorUser->telefono)) {
            return false;
        }

        $cacheKey = "whatsapp_sent_revision_{$workOrder->id}";
        if (Cache::get($cacheKey, false)) {
            return false; // Ya se envió
        }

        $message = "*Tarea Enviada a Revisión*\n\n" .
            "🔍 Estado: En Revisión\n" .
            "Título: " . $workOrder->subject . "\n" .
            "Enviado por: " . $senderUser->name . "\n";

        if ($documentName) {
            $message .= "Documento: " . $documentName . "\n";
        }

        $message .= "\n*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        $result = $this->sendWhatsAppMessage(
            $creatorUser->telefono,
            $message,
            'workorder_revision_' . $workOrder->id,
            'Tarea en revisión',
            [
                'usuario_creador_id' => $creatorUser->id,
                'usuario_que_envia_id' => $senderUser->id,
                'work_order_id' => $workOrder->id,
                'type' => 'revision'
            ]
        );

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
        }

        return $result;
    }

    /**
     * Enviar notificación de tarea re-enviada a revisión
     */
    public function sendTaskResubmissionNotification($creatorUser, $workOrder, $senderUser)
    {

        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl =  "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($creatorUser->telefono)) {
            return false;
        }

        $cacheKey = "whatsapp_sent_reentrega_vinculo_{$workOrder->id}";
        if (Cache::get($cacheKey, false)) {
            return false; // Ya se envió
        }

        $message = "*Tarea re-enviada a Revisión*\n\n" .
            "🔄️ Estado: En Revisión\n" .
            "Título: " . $workOrder->subject . "\n" .
            "Re-enviado por: " . $senderUser->name . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        $result = $this->sendWhatsAppMessage(
            $creatorUser->telefono,
            $message,
            'workorder_reentrega_vinculo_' . $workOrder->id,
            'Tarea re-enviada',
            [
                'usuario_creador_id' => $creatorUser->id,
                'usuario_que_envia_id' => $senderUser->id,
                'work_order_id' => $workOrder->id,
                'type' => 're_entrega'
            ]
        );

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
        }

        return $result;
    }

    /**
     * Enviar notificación de tarea devuelta
     */
    public function sendTaskRejectedNotification($responsableUser, $workOrder, $creatorUser)
    {
        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl =  "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($responsableUser->telefono)) {
            return false;
        }

        $cacheKey = "whatsapp_sent_cancelar_{$workOrder->id}";
        if (Cache::get($cacheKey, false)) {
            return false; // Ya se envió
        }

        $message = "*Tarea devuelta para corrección*\n\n" .
            "❌ Estado: Devuelta\n" .
            "Título: " . $workOrder->subject . "\n" .
            "Devuelta por: " . ($creatorUser ? $creatorUser->name : 'Sistema') . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        $result = $this->sendWhatsAppMessage(
            $responsableUser->telefono,
            $message,
            'workorder_cancelar_' . $workOrder->id,
            'Tarea devuelta',
            [
                'usuario_responsable_id' => $responsableUser->id,
                'work_order_id' => $workOrder->id,
                'type' => 'devuelta'
            ]
        );

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
        }

        return $result;
    }

    /**
     * Enviar notificación de tarea aceptada
     */
    public function sendTaskAcceptedNotification($responsableUser, $workOrder, $creatorUser)
    {
        $user = Auth::user();
        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl = "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($responsableUser->telefono)) {
            Log::warning('Usuario responsable sin teléfono para notificación', [
                'user_id' => $responsableUser->id,
                'work_order_id' => $workOrder->id
            ]);
            return false;
        }

        $cacheKey = "whatsapp_sent_aceptar_{$workOrder->id}";
        if (Cache::get($cacheKey, false)) {
            Log::info('Notificación de tarea aceptada ya enviada (cache)', [
                'cache_key' => $cacheKey,
                'work_order_id' => $workOrder->id
            ]);
            return false; // Ya se envió
        }

        $message = "*Tarea aceptada y finalizada*\n\n" .
            "✅ Estado: Aceptada\n" .
            "Título: " . $workOrder->subject . "\n" .
            "Aceptada por: " . ($creatorUser ? $creatorUser->name : 'Sistema') . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        Log::info('Enviando notificación de tarea aceptada', [
            'responsable_user_id' => $responsableUser->id,
            'responsable_user_name' => $responsableUser->name,
            'work_order_id' => $workOrder->id,
            'work_order_subject' => $workOrder->subject,
            'accepted_by' => $creatorUser ? $creatorUser->name : 'Sistema'
        ]);

        $result = $this->sendWhatsAppMessage(
            $responsableUser->telefono,
            $message,
            'workorder_aceptar_' . $workOrder->id,
            'Tarea aceptada',
            [
                'usuario_responsable_id' => $responsableUser->id,
                'work_order_id' => $workOrder->id,
                'type' => 'aceptada'
            ]
        );

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
            Log::info('Notificación de tarea aceptada enviada exitosamente', [
                'work_order_id' => $workOrder->id,
                'responsable_user_id' => $responsableUser->id
            ]);
        } else {
            Log::error('Error al enviar notificación de tarea aceptada', [
                'work_order_id' => $workOrder->id,
                'responsable_user_id' => $responsableUser->id
            ]);
        }

        // 🆕 NUEVA FUNCIONALIDAD: Notificar también a los grupos de los proyectos asociados
        $this->sendTaskAcceptedGroupNotification($workOrder, $creatorUser);

        return $result;
    }

    /**
     * Enviar notificación al grupo de WhatsApp cuando se acepta una tarea
     */
    private function sendTaskAcceptedGroupNotification($workOrder, $creatorUser)
    {
        try {
            Log::info('Iniciando notificaciones grupales para tarea aceptada', [
                'work_order_id' => $workOrder->id,
                'work_order_subject' => $workOrder->subject,
                'accepted_by' => $creatorUser ? $creatorUser->name : 'Sistema'
            ]);

            $projects = WorkOrderProjects::where('work_order_id', $workOrder->id)
                ->with('project')
                ->get();

            Log::info('Proyectos encontrados para work order', [
                'work_order_id' => $workOrder->id,
                'projects_count' => $projects->count(),
                'projects_data' => $projects->map(function ($wp) {
                    return [
                        'project_id' => $wp->project_id,
                        'project_name' => $wp->project->titulo ?? 'Sin título',
                        'has_whatsapp_group' => !empty($wp->project->grupo_whats),
                        'group_name' => $wp->project->grupo_whats ?? null
                    ];
                })->toArray()
            ]);

            if ($projects->isEmpty()) {
                Log::info('No hay proyectos asociados a esta tarea', [
                    'work_order_id' => $workOrder->id
                ]);
                return false;
            }

            // Instanciar el servicio de WhatsApp para grupos
            $whatsappGroupService = app(SendWhatsappMessagesGroupService::class);
            Log::info('Servicio SendWhatsappMessagesGroupService instanciado correctamente para tarea aceptada');

            $successCount = 0;
            $errorCount = 0;

            foreach ($projects as $workOrderProject) {
                $project = $workOrderProject->project;

                if (!$project) {
                    Log::warning('Proyecto no encontrado en relación work_order_projects', [
                        'work_order_project_id' => $workOrderProject->id,
                        'project_id' => $workOrderProject->project_id,
                        'work_order_id' => $workOrder->id
                    ]);
                    $errorCount++;
                    continue;
                }

                if (empty($project->grupo_whats)) {
                    Log::info('Proyecto sin grupo de WhatsApp configurado', [
                        'project_id' => $project->id,
                        'project_name' => $project->titulo,
                        'work_order_id' => $workOrder->id
                    ]);
                    continue;
                }

                // Crear clave de cache única para cada proyecto
                $cacheKey = "whatsapp_task_accepted_{$project->id}_{$workOrder->id}";
                if (Cache::get($cacheKey, false)) {
                    Log::info('Notificación grupal de tarea aceptada ya enviada (cache)', [
                        'project_id' => $project->id,
                        'work_order_id' => $workOrder->id,
                        'cache_key' => $cacheKey
                    ]);
                    continue; // Ya se envió a este proyecto
                }

                // Preparar mensaje para el grupo
                $acceptedByName = $creatorUser ? $creatorUser->name : 'Sistema';
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $taskUrl = "{$frontUrl}/tarea/detalle/{$workOrder->id}";

                $groupMessage = "*✅ Tarea aceptada y finalizada*\n\n" .
                    "📋 **Proyecto:** " . ($project->titulo ?? 'Sin título') . "\n" .
                    "📝 **Tarea:** " . ($workOrder->subject ?? 'Sin título') . "\n" .
                    "✅ **Estado:** Aceptada\n" .
                    "👤 **Aceptada por:** {$acceptedByName}\n" .
                    "📅 **Fecha:** " . now()->format('d/m/Y H:i') . "\n\n" .
                    "💡 **Ver detalles:** {$taskUrl}";

                Log::info('Preparando envío de mensaje grupal para tarea aceptada', [
                    'project_id' => $project->id,
                    'project_name' => $project->titulo,
                    'group_name' => $project->grupo_whats,
                    'work_order_id' => $workOrder->id,
                    'accepted_by' => $acceptedByName,
                    'message_length' => strlen($groupMessage)
                ]);

                // Enviar mensaje al grupo del proyecto usando el servicio
                $result = $whatsappGroupService->sendMessageToProjectGroup(
                    $project->id,
                    $groupMessage
                );

                Log::info('Resultado del envío de mensaje grupal para tarea aceptada', [
                    'project_id' => $project->id,
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? 'Sin mensaje',
                    'group_id' => $result['data']['group_id'] ?? 'N/A',
                    'group_name' => $result['data']['group_name'] ?? 'N/A',
                    'ultramsg_response' => $result['data']['ultramsg_response'] ?? null
                ]);

                if ($result['success']) {
                    // Guardar en cache por 5 minutos para evitar duplicados
                    Cache::put($cacheKey, true, 300);
                    $successCount++;

                    Log::info('✅ Notificación grupal de tarea aceptada enviada exitosamente', [
                        'project_id' => $project->id,
                        'project_name' => $project->titulo,
                        'work_order_id' => $workOrder->id,
                        'group_id' => $result['data']['group_id'] ?? 'N/A',
                        'group_name' => $result['data']['group_name'] ?? 'N/A',
                        'accepted_by' => $acceptedByName,
                        'cache_key' => $cacheKey
                    ]);
                } else {
                    $errorCount++;
                    Log::error('❌ Error al enviar notificación grupal de tarea aceptada', [
                        'project_id' => $project->id,
                        'project_name' => $project->titulo,
                        'work_order_id' => $workOrder->id,
                        'error_message' => $result['message'] ?? 'Error desconocido',
                        'error_details' => $result['error'] ?? null,
                        'result_data' => $result['data'] ?? null
                    ]);
                }
            }

            Log::info('Resumen de notificaciones grupales para tarea aceptada', [
                'work_order_id' => $workOrder->id,
                'total_projects' => $projects->count(),
                'success_count' => $successCount,
                'error_count' => $errorCount
            ]);

            return $successCount > 0;
        } catch (\Throwable $e) {
            Log::error('❌ Excepción crítica en notificaciones grupales de tarea aceptada', [
                'work_order_id' => $workOrder->id ?? null,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }

    /**
     * Enviar notificación individual de trabajo rechazado
     */
    public function sendIndividualWorkRejectedNotification($targetUser, $workOrder, $creatorUser, $userNume)
    {

        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl =  "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($targetUser->telefono)) {
            return false;
        }

        $cacheKey = "whatsapp_sent_rechazar_individual_{$workOrder->id}_{$userNume}";
        if (Cache::get($cacheKey, false)) {
            return false; // Ya se envió
        }

        $message = "*Tarea devuelta para corrección*\n\n" .
            "❌ Estado: Devuelta\n" .
            "Título: " . $workOrder->subject . "\n" .
            "Devuelto por: " . ($creatorUser ? $creatorUser->name : 'Sistema') . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        $result = $this->sendWhatsAppMessage(
            $targetUser->telefono,
            $message,
            'workorder_rechazar_individual_' . $workOrder->id . '_' . $userNume,
            'Trabajo individual rechazado',
            [
                'usuario_rechazado_id' => $targetUser->id,
                'work_order_id' => $workOrder->id,
                'type' => 'rechazo_individual'
            ]
        );

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
        }

        return $result;
    }

    /**
     * Enviar notificación individual de trabajo aceptado
     */
    public function sendIndividualWorkAcceptedNotification($targetUser, $workOrder, $creatorUser, $userNume)
    {
        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl = "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        if (empty($targetUser->telefono)) {
            Log::warning('Usuario sin teléfono para notificación individual', [
                'user_id' => $targetUser->id,
                'work_order_id' => $workOrder->id,
                'user_nume' => $userNume
            ]);
            return false;
        }

        $cacheKey = "whatsapp_sent_aceptar_individual_{$workOrder->id}_{$userNume}";
        if (Cache::get($cacheKey, false)) {
            Log::info('Notificación individual ya enviada (cache)', [
                'cache_key' => $cacheKey,
                'work_order_id' => $workOrder->id,
                'user_nume' => $userNume
            ]);
            return false; // Ya se envió
        }

        $message = "*Tarea aceptada y finalizada*\n\n" .
            "✅ Estado: Aceptada\n" .
            "Título: " . $workOrder->subject . "\n" .
            "Aceptado por: " . ($creatorUser ? $creatorUser->name : 'Sistema') . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        Log::info('Enviando notificación individual de trabajo aceptado', [
            'target_user_id' => $targetUser->id,
            'target_user_name' => $targetUser->name,
            'work_order_id' => $workOrder->id,
            'work_order_subject' => $workOrder->subject,
            'user_nume' => $userNume,
            'accepted_by' => $creatorUser ? $creatorUser->name : 'Sistema'
        ]);

        $result = $this->sendWhatsAppMessage(
            $targetUser->telefono,
            $message,
            'workorder_aceptar_individual_' . $workOrder->id . '_' . $userNume,
            'Trabajo individual aceptado',
            [
                'usuario_aceptado_id' => $targetUser->id,
                'work_order_id' => $workOrder->id,
                'type' => 'aceptacion_individual'
            ]
        );

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
            Log::info('Notificación individual enviada exitosamente', [
                'work_order_id' => $workOrder->id,
                'user_nume' => $userNume,
                'target_user_id' => $targetUser->id
            ]);
        } else {
            Log::error('Error al enviar notificación individual', [
                'work_order_id' => $workOrder->id,
                'user_nume' => $userNume,
                'target_user_id' => $targetUser->id
            ]);
        }

        // 🆕 NUEVA FUNCIONALIDAD: Notificar también a los grupos de los proyectos asociados
        $this->sendIndividualWorkAcceptedGroupNotification($targetUser, $workOrder, $creatorUser, $userNume);

        return $result;
    }

    /**
     * Enviar notificación al grupo de WhatsApp cuando se acepta un trabajo individual
     */
    private function sendIndividualWorkAcceptedGroupNotification($targetUser, $workOrder, $creatorUser, $userNume)
    {
        try {
            Log::info('Iniciando notificaciones grupales para trabajo individual aceptado', [
                'work_order_id' => $workOrder->id,
                'target_user_id' => $targetUser->id,
                'user_nume' => $userNume
            ]);

            // Obtener todos los proyectos asociados a esta work order
            $projects = WorkOrderProjects::where('work_order_id', $workOrder->id)
                ->with('project')
                ->get();

            Log::info('Proyectos encontrados para work order', [
                'work_order_id' => $workOrder->id,
                'projects_count' => $projects->count(),
                'projects_data' => $projects->map(function ($wp) {
                    return [
                        'project_id' => $wp->project_id,
                        'project_name' => $wp->project->titulo ?? 'Sin título',
                        'has_whatsapp_group' => !empty($wp->project->grupo_whats)
                    ];
                })->toArray()
            ]);

            if ($projects->isEmpty()) {
                Log::info('No hay proyectos asociados a esta tarea individual', [
                    'work_order_id' => $workOrder->id,
                    'user_nume' => $userNume
                ]);
                return false;
            }

            // Instanciar el servicio de WhatsApp para grupos
            $whatsappGroupService = app(SendWhatsappMessagesGroupService::class);
            Log::info('Servicio SendWhatsappMessagesGroupService instanciado correctamente');

            $successCount = 0;
            $errorCount = 0;

            foreach ($projects as $workOrderProject) {
                $project = $workOrderProject->project;

                if (!$project) {
                    Log::warning('Proyecto no encontrado en relación work_order_projects', [
                        'work_order_project_id' => $workOrderProject->id,
                        'project_id' => $workOrderProject->project_id,
                        'work_order_id' => $workOrder->id
                    ]);
                    $errorCount++;
                    continue;
                }

                if (empty($project->grupo_whats)) {
                    Log::info('Proyecto sin grupo de WhatsApp configurado', [
                        'project_id' => $project->id,
                        'project_name' => $project->titulo,
                        'work_order_id' => $workOrder->id,
                        'user_nume' => $userNume
                    ]);
                    continue;
                }

                // Crear clave de cache única para cada proyecto y usuario
                $cacheKey = "whatsapp_individual_accepted_{$project->id}_{$workOrder->id}_{$userNume}";
                if (Cache::get($cacheKey, false)) {
                    Log::info('Notificación grupal ya enviada para este proyecto (cache)', [
                        'project_id' => $project->id,
                        'work_order_id' => $workOrder->id,
                        'user_nume' => $userNume,
                        'cache_key' => $cacheKey
                    ]);
                    continue; // Ya se envió a este proyecto
                }

                // Preparar mensaje para el grupo
                $acceptedByName = $creatorUser ? $creatorUser->name : 'Sistema';
                $targetUserName = $targetUser->name ?? 'Usuario';
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $taskUrl = "{$frontUrl}/tarea/detalle/{$workOrder->id}";

                $groupMessage = "*✅ Trabajo individual aceptado*\n\n" .
                    "📋 **Proyecto:** " . ($project->titulo ?? 'Sin título') . "\n" .
                    "📝 **Tarea:** " . ($workOrder->subject ?? 'Sin título') . "\n" .
                    "👤 **Asignado a:** {$targetUserName}\n" .
                    "✅ **Estado:** Aceptado\n" .
                    "🔧 **Aceptado por:** {$acceptedByName}\n" .
                    "📅 **Fecha:** " . now()->format('d/m/Y H:i') . "\n\n" .
                    "💡 **Ver detalles:** {$taskUrl}";

                Log::info('Preparando envío de mensaje grupal', [
                    'project_id' => $project->id,
                    'project_name' => $project->titulo,
                    'group_name' => $project->grupo_whats,
                    'work_order_id' => $workOrder->id,
                    'target_user_name' => $targetUserName,
                    'accepted_by' => $acceptedByName,
                    'message_length' => strlen($groupMessage)
                ]);

                // Enviar mensaje al grupo del proyecto usando el servicio
                $result = $whatsappGroupService->sendMessageToProjectGroup(
                    $project->id,
                    $groupMessage
                );

                Log::info('Resultado del envío de mensaje grupal', [
                    'project_id' => $project->id,
                    'success' => $result['success'] ?? false,
                    'message' => $result['message'] ?? 'Sin mensaje',
                    'group_id' => $result['data']['group_id'] ?? 'N/A',
                    'group_name' => $result['data']['group_name'] ?? 'N/A',
                    'ultramsg_response' => $result['data']['ultramsg_response'] ?? null
                ]);

                if ($result['success']) {
                    // Guardar en cache por 5 minutos para evitar duplicados
                    Cache::put($cacheKey, true, 300);
                    $successCount++;

                    Log::info('✅ Notificación grupal enviada exitosamente', [
                        'project_id' => $project->id,
                        'project_name' => $project->titulo,
                        'work_order_id' => $workOrder->id,
                        'target_user_id' => $targetUser->id,
                        'target_user_name' => $targetUserName,
                        'user_nume' => $userNume,
                        'group_id' => $result['data']['group_id'] ?? 'N/A',
                        'group_name' => $result['data']['group_name'] ?? 'N/A',
                        'accepted_by' => $acceptedByName,
                        'cache_key' => $cacheKey
                    ]);
                } else {
                    $errorCount++;
                    Log::error('❌ Error al enviar notificación grupal', [
                        'project_id' => $project->id,
                        'project_name' => $project->titulo,
                        'work_order_id' => $workOrder->id,
                        'target_user_id' => $targetUser->id,
                        'user_nume' => $userNume,
                        'error_message' => $result['message'] ?? 'Error desconocido',
                        'error_details' => $result['error'] ?? null,
                        'result_data' => $result['data'] ?? null
                    ]);
                }
            }

            Log::info('Resumen de notificaciones grupales para trabajo individual', [
                'work_order_id' => $workOrder->id,
                'user_nume' => $userNume,
                'total_projects' => $projects->count(),
                'success_count' => $successCount,
                'error_count' => $errorCount
            ]);

            return $successCount > 0;
        } catch (\Throwable $e) {
            Log::error('❌ Excepción crítica en notificaciones grupales de trabajo individual', [
                'work_order_id' => $workOrder->id ?? null,
                'target_user_id' => $targetUser->id ?? null,
                'user_nume' => $userNume ?? null,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return false;
        }
    }



    /**
     * Método privado para enviar WhatsApp y manejar logs
     */
    private function sendWhatsAppMessage($telefono, $message, $referenceId, $logContext, $logData = [])
    {
        try {
            Log::info("Enviando mensaje WhatsApp", [
                'telefono' => $telefono,
                'message_preview' => mb_substr($message, 0, 100, 'UTF-8'), // Usar mb_substr aquí también
                'message_length' => mb_strlen($message, 'UTF-8'),
                'context' => $logContext
            ]);

            $response = $this->whatsappService->sendMessage(
                $telefono,
                $message,
                1,
                $referenceId,
                '',
                ''
            );

            if ($response['success']) {
                Log::info("WhatsApp enviado exitosamente ({$logContext})", array_merge($logData, [
                    'telefono' => $telefono,
                    'response' => $response['data'] ?? null
                ]));
                return true;
            } else {
                Log::error("Error al enviar WhatsApp ({$logContext})", array_merge($logData, [
                    'telefono' => $telefono,
                    'error' => $response['error'] ?? 'Error desconocido'
                ]));
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Excepción al enviar WhatsApp ({$logContext})", array_merge($logData, [
                'telefono' => $telefono,
                'exception' => $e->getMessage()
            ]));
            return false;
        }
    }
    public function mensajeNodica($telefono, $message, $referenceId, $logContext, $logData = []){
        return $this->sendWhatsAppMessage($telefono, $message, $referenceId, $logContext, $logData);
    }

    /**
     * Método para decodificar el mensaje y limpiar caracteres problemáticos
     */
    private function decodeMessage($message)
    {
        // Opción 1: Si el mensaje ya viene URL encoded, decodificarlo
        $decoded = urldecode($message);

        // Opción 2: Si el problema persiste, limpiar caracteres problemáticos
        $decoded = $this->cleanMessageForWhatsApp($decoded);

        return $decoded;
    }


    /**
     * Limpiar mensaje para WhatsApp
     */
    private function cleanMessageForWhatsApp($message)
    {
        // Convertir entidades HTML si existen
        $message = html_entity_decode($message, ENT_QUOTES, 'UTF-8');

        // Asegurar que los saltos de línea sean correctos
        $message = str_replace(['\n', '\r\n', '\r'], "\n", $message);

        // Limpiar caracteres de control excepto saltos de línea y tabs
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message);

        // Normalizar espacios múltiples
        $message = preg_replace('/[ \t]+/', ' ', $message);

        return trim($message);
    }


    /**
     * Enviar notificación de nuevo comentario a todos los usuarios del workorder
     */
    public function sendNewCommentNotification($workOrder, $commentUser, $commentContent, $hasAttachments = false)
    {
        $user = Auth::user();

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
        } else {
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl = "{$frontUrl}tarea/detalle/{$workOrder->id}";
            }
        }

        $relatedUsers = $workOrder->users()->get();

        $message = "*Nuevo comentario en tarea*\n\n" .
            "💬 Comentario de: " . $commentUser->name . "\n" .
            "Tarea: " . $workOrder->subject . "\n";

        // SOLUCIÓN: Usar mb_substr() para truncar correctamente caracteres UTF-8
        if (!empty($commentContent)) {
            $shortContent = mb_strlen($commentContent, 'UTF-8') > 80
                ? mb_substr($commentContent, 0, 80, 'UTF-8') . '...'
                : $commentContent;
            $message .= "Mensaje: " . $shortContent . "\n";
        }

        if ($hasAttachments) {
            $message .= "📎 Incluye archivos adjuntos\n";
        }

        $message .= "\n*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        Log::info('Mensaje preparado para WhatsApp:', [
            'work_order_id' => $workOrder->id,
            'comment_user' => $commentUser->name,
            'original_content' => $commentContent,
            'original_length' => mb_strlen($commentContent, 'UTF-8'),
            'truncated_content' => !empty($commentContent) ? (mb_strlen($commentContent, 'UTF-8') > 80 ? mb_substr($commentContent, 0, 80, 'UTF-8') . '...' : $commentContent) : '',
            'final_message' => $message,
            'message_encoding' => mb_detect_encoding($message)
        ]);

        $sentCount = 0;
        $errors = [];

        foreach ($relatedUsers as $usuario) {
            if ($usuario->id == $commentUser->id) {
                continue;
            }

            if (empty($usuario->telefono)) {
                continue;
            }

            try {
                $result = $this->sendWhatsAppMessage(
                    $usuario->telefono,
                    $message,
                    'workorder_comment_' . $workOrder->id . '_' . time(),
                    'Nuevo comentario',
                    [
                        'usuario_destinatario_id' => $usuario->id,
                        'usuario_comentario_id' => $commentUser->id,
                        'work_order_id' => $workOrder->id,
                        'type' => 'nuevo_comentario'
                    ]
                );

                if ($result) {
                    $sentCount++;
                } else {
                    $errors[] = "Error enviando a usuario {$usuario->id}";
                }
            } catch (\Exception $e) {
                $errors[] = "Excepción enviando a usuario {$usuario->id}: " . $e->getMessage();
            }
        }

        Log::info('Resumen envío comentarios WhatsApp', [
            'work_order_id' => $workOrder->id,
            'comentario_usuario' => $commentUser->name,
            'usuarios_notificados' => $sentCount,
            'total_usuarios' => count($relatedUsers) - 1,
            'errores' => count($errors)
        ]);

        return $sentCount > 0;
    }


    /**
     * Método auxiliar para convertir el número de prioridad a texto
     */
    private function getPriorityText($priority)
    {
        switch ($priority) {
            case 1:
                return '🔴 Urgente';
            case 2:
                return '🟠 Alta';
            case 3:
                return '🟡 Media';
            case 4:
                return '🟢 Baja';
            case 0:
                return '⚪ Muy Baja';
            default:
                return '🟢 Baja';
        }
    }




    /**
     * Enviar notificación cuando un usuario inicia una tarea
     */
    /**
     * Enviar notificación cuando un usuario inicia una tarea
     */
    public function sendTaskStartedNotification($creatorUser, $workOrder, $userWhoStarted, $productionTime)
    {
        // LOG 1: Información inicial
        Log::info('=== INICIO sendTaskStartedNotification ===', [
            'creator_user_id' => $creatorUser ? $creatorUser->id : 'NULL',
            'creator_user_name' => $creatorUser ? $creatorUser->name : 'NULL',
            'creator_user_phone' => $creatorUser ? $creatorUser->telefono : 'NULL',
            'work_order_id' => $workOrder ? $workOrder->id : 'NULL',
            'work_order_subject' => $workOrder ? $workOrder->subject : 'NULL',
            'user_started_id' => $userWhoStarted ? $userWhoStarted->id : 'NULL',
            'user_started_name' => $userWhoStarted ? $userWhoStarted->name : 'NULL',
            'production_time' => $productionTime
        ]);

        $user = Auth::user();

        // LOG 2: Usuario autenticado
        Log::info('Usuario autenticado:', [
            'auth_user_id' => $user ? $user->id : 'NULL',
            'auth_user_type' => $user ? $user->type : 'NULL',
            'auth_user_sub_type' => $user ? ($user->sub_type ?? 'NULL') : 'NULL'
        ]);

        if (!$user) {
            $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
            Log::info('No hay usuario autenticado, usando URL por defecto:', ['url' => $this->frontUrl]);
        } else {
            // Verificar si cumple las condiciones
            $canAccess = ($user->type === 'bitgob') ||
                ($user->type === 'superadmin' && $user->sub_type === 'bitgob');

            Log::info('Verificando acceso:', [
                'can_access' => $canAccess,
                'user_type' => $user->type,
                'user_sub_type' => $user->sub_type ?? 'NULL'
            ]);

            if (!$canAccess) {
                $this->frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                Log::info('Sin acceso especial, usando URL por defecto:', ['url' => $this->frontUrl]);
            } else {
                $frontUrl = config('app.frontend_url', 'https://yali.bitgob.com');
                $this->frontUrl = "{$frontUrl}tarea/detalle/{$workOrder->id}";
                Log::info('Con acceso especial, usando URL específica:', ['url' => $this->frontUrl]);
            }
        }

        // LOG 3: Validación del teléfono del creador
        if (empty($creatorUser->telefono)) {
            Log::warning('FALLO: El usuario creador no tiene teléfono configurado', [
                'creator_user_id' => $creatorUser->id,
                'creator_user_name' => $creatorUser->name,
                'telefono' => $creatorUser->telefono
            ]);
            return false;
        }

        Log::info('Usuario creador tiene teléfono:', ['telefono' => $creatorUser->telefono]);

        // LOG 4: Verificación de cache
        $cacheKey = "whatsapp_sent_task_started_{$workOrder->id}_{$userWhoStarted->id}";
        $cacheExists = Cache::get($cacheKey, false);

        Log::info('Verificando cache:', [
            'cache_key' => $cacheKey,
            'cache_exists' => $cacheExists
        ]);

        if ($cacheExists) {
            Log::warning('FALLO: Notificación ya enviada según cache', ['cache_key' => $cacheKey]);
            return false; // Ya se envió
        }

        // LOG 5: Preparación del mensaje
        $timeText = $productionTime == 1 ? "1 hora" : "{$productionTime} horas";

        Log::info('Preparando mensaje:', [
            'production_time' => $productionTime,
            'time_text' => $timeText
        ]);

        $message = "*Tarea iniciada*\n\n" .
            "Título: " . $workOrder->subject . "\n" .
            "El tiempo de producción estimado de "  . $userWhoStarted->name . " es de "  . $timeText . "\n\n" .
            "*💡 Revisa Yali en {$this->frontUrl} para más detalles*";

        Log::info('Mensaje preparado:', ['message' => $message]);

        // LOG 6: Preparación de datos para envío
        $referenceId = 'workorder_started_' . $workOrder->id . '_' . $userWhoStarted->id;
        $logData = [
            'usuario_creador_id' => $creatorUser->id,
            'usuario_que_inicia_id' => $userWhoStarted->id,
            'work_order_id' => $workOrder->id,
            'production_time' => $productionTime,
            'type' => 'tarea_iniciada'
        ];

        Log::info('Datos para WhatsApp:', [
            'telefono' => $creatorUser->telefono,
            'reference_id' => $referenceId,
            'log_data' => $logData
        ]);

        // LOG 7: Antes del envío
        Log::info('=== ENVIANDO WHATSAPP ===');

        $result = $this->sendWhatsAppMessage(
            $creatorUser->telefono,
            $message,
            $referenceId,
            'Tarea iniciada',
            $logData
        );

        // LOG 8: Resultado del envío
        Log::info('Resultado del envío WhatsApp:', [
            'success' => $result,
            'reference_id' => $referenceId
        ]);

        if ($result) {
            Cache::put($cacheKey, true, 300); // Cache por 5 minutos
            Log::info('Cache guardado exitosamente:', ['cache_key' => $cacheKey]);
        } else {
            Log::error('FALLO: No se pudo enviar el WhatsApp');
        }

        Log::info('=== FIN sendTaskStartedNotification ===', ['final_result' => $result]);

        return $result;
    }
}