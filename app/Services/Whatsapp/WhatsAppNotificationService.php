<?php

namespace App\Services\Whatsapp;

use App\Models\WorkOrder;
use App\Models\TaskParticipant;
use App\Models\UserFirebirdIdentity;
use Illuminate\Support\Facades\Log;

class WhatsAppNotificationService
{
    private $ultraMsgService;

    public function __construct(UltraMSGService $ultraMsgService)
    {
        $this->ultraMsgService = $ultraMsgService;
    }

    public function notifyParticipants(WorkOrder $workorder): array
    {
        $results = [];

        try {
            $workorder = $workorder->fresh([
                'taskParticipants.user',
                'de.firebirdUser' // 👈 Cargar firebirdUser para acceder a USUARIOS.NOMBRE
            ]);

            $participants = $workorder->taskParticipants;

            // 🔥 LOG SUPER DETALLADO: Ver TODOS los participants con sus roles
            $participantsDetail = $participants->map(function ($p) {
                return [
                    'participant_id' => $p->id,
                    'user_id' => $p->user_id,
                    'role' => $p->role,
                    'firebird_user_clave' => $p->user->firebird_user_clave ?? 'N/A',
                ];
            })->toArray();

            Log::info('WorkorderNotification - Participantes encontrados', [
                'workorder_id' => $workorder->id,
                'participants_count' => $participants->count(),
                'titulo' => $workorder->titulo,
                'participants_ids' => $participants->pluck('id')->toArray(),
                'participants_user_ids' => $participants->pluck('user_id')->toArray(),
                'participants_roles' => $participants->pluck('role')->toArray(),
                'de_id' => $workorder->de_id,
                'de_loaded' => $workorder->de ? 'YES' : 'NO',
                'de_firebird_tb_tabla' => $workorder->de->firebird_tb_tabla ?? 'N/A',
                'de_firebird_tb_clave' => $workorder->de->firebird_tb_clave ?? 'N/A',
                'PARTICIPANTS_DETAIL' => $participantsDetail,
            ]);

            // 🔥 Contar por role
            $roleCount = $participants->groupBy('role')->map(fn($g) => $g->count())->toArray();
            Log::info('WorkorderNotification - Conteo por rol', [
                'workorder_id' => $workorder->id,
                'role_counts' => $roleCount,
            ]);

            if ($participants->isEmpty()) {
                Log::warning('WorkorderNotification - No hay participantes', [
                    'workorder_id' => $workorder->id
                ]);

                return [
                    'success' => true,
                    'workorder_id' => $workorder->id,
                    'notifications_sent' => 0,
                    'total_participants' => 0,
                    'results' => [],
                    'message' => 'No hay participantes para notificar'
                ];
            }

            foreach ($participants as $index => $participant) {
                try {
                    Log::info('WorkorderNotification - Procesando participante', [
                        'index' => $index + 1,
                        'total' => $participants->count(),
                        'participant_id' => $participant->id,
                        'user_id' => $participant->user_id,
                        'role' => $participant->role,
                        'workorder_id' => $workorder->id
                    ]);

                    if (!$participant->user) {
                        Log::error('WorkorderNotification - Participante sin user', [
                            'participant_id' => $participant->id,
                            'user_id' => $participant->user_id
                        ]);

                        $results[] = [
                            'user_id' => $participant->user_id,
                            'success' => false,
                            'error' => 'Usuario no encontrado en UserFirebirdIdentity'
                        ];
                        continue;
                    }

                    $phoneNumber = $this->getParticipantPhone($participant->user);

                    if (!$phoneNumber) {
                        Log::warning('WorkorderNotification - Sin teléfono', [
                            'participant_id' => $participant->id,
                            'user_id' => $participant->user_id,
                            'firebird_clave' => $participant->user->firebird_user_clave ?? 'N/A',
                            'firebird_tb_clave' => $participant->user->firebird_tb_clave ?? 'N/A',
                            'firebird_tb_tabla' => $participant->user->firebird_tb_tabla ?? 'N/A'
                        ]);

                        $results[] = [
                            'user_id' => $participant->user_id,
                            'success' => false,
                            'error' => 'Teléfono no encontrado'
                        ];
                        continue;
                    }

                    Log::info('WorkorderNotification - Construyendo mensaje', [
                        'participant_id' => $participant->id,
                        'phone' => $phoneNumber
                    ]);

                    $message = $this->buildNotificationMessage($workorder, $participant);

                    Log::info('WorkorderNotification - Enviando mensaje', [
                        'participant_id' => $participant->id,
                        'phone' => $phoneNumber,
                        'message_preview' => substr($message, 0, 100)
                    ]);

                    $response = $this->ultraMsgService->sendMessage(
                        to: $phoneNumber,
                        body: $message,
                        priority: 1
                    );

                    Log::info('WorkorderNotification - Mensaje enviado', [
                        'participant_id' => $participant->id,
                        'user_id' => $participant->user_id,
                        'phone' => $phoneNumber,
                        'success' => $response['success'],
                        'workorder_id' => $workorder->id,
                        'response_status' => $response['status_code'] ?? 'N/A'
                    ]);

                    $results[] = [
                        'participant_id' => $participant->id,
                        'user_id' => $participant->user_id,
                        'phone' => $phoneNumber,
                        'success' => $response['success'],
                        'response' => $response
                    ];

                    if ($index < $participants->count() - 1) {
                        usleep(500000); // 0.5 segundos entre mensajes
                    }
                } catch (\Exception $e) {
                    Log::error('WorkorderNotification - Error por participante', [
                        'participant_id' => $participant->id ?? 'N/A',
                        'user_id' => $participant->user_id ?? 'N/A',
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    $results[] = [
                        'participant_id' => $participant->id ?? null,
                        'user_id' => $participant->user_id ?? null,
                        'success' => false,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $successCount = count(array_filter($results, fn($r) => $r['success'] ?? false));

            Log::info('WorkorderNotification - Proceso completado', [
                'workorder_id' => $workorder->id,
                'total_participants' => $participants->count(),
                'notifications_sent' => $successCount,
                'failures' => $participants->count() - $successCount
            ]);

            return [
                'success' => true,
                'workorder_id' => $workorder->id,
                'notifications_sent' => $successCount,
                'total_participants' => $participants->count(),
                'results' => $results
            ];
        } catch (\Exception $e) {
            Log::error('WorkorderNotification - Error general', [
                'workorder_id' => $workorder->id ?? 'N/A',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'workorder_id' => $workorder->id ?? null,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getParticipantPhone(UserFirebirdIdentity $identity): ?string
    {
        try {
            Log::info('getParticipantPhone - Inicio', [
                'identity_id' => $identity->id,
                'firebird_user_clave' => $identity->firebird_user_clave,
                'firebird_tb_clave' => $identity->firebird_tb_clave,
                'firebird_tb_tabla' => $identity->firebird_tb_tabla,
                'firebird_empresa' => $identity->firebird_empresa
            ]);

            $tbData = $identity->getTbData();

            if (!$tbData) {
                Log::warning('getParticipantPhone - No se pudo obtener datos de TB', [
                    'identity_id' => $identity->id,
                    'tb_tabla' => $identity->firebird_tb_tabla,
                    'tb_clave' => $identity->firebird_tb_clave,
                    'empresa' => $identity->firebird_empresa
                ]);
                return null;
            }

            $phone = $tbData->TELEFONO
                ?? $tbData->TELEFONO2
                ?? $tbData->CELULAR
                ?? null;

            if (!$phone) {
                $availableFields = get_object_vars($tbData);
                Log::warning('getParticipantPhone - Sin teléfono en TB', [
                    'identity_id' => $identity->id,
                    'tb_data_fields' => array_keys($availableFields),
                    'nombre' => $tbData->NOMBRE ?? 'N/A'
                ]);
                return null;
            }

            $formattedPhone = $this->formatPhoneNumber($phone);

            Log::info('getParticipantPhone - Teléfono obtenido', [
                'identity_id' => $identity->id,
                'tb_tabla' => $identity->firebird_tb_tabla,
                'phone_raw' => $phone,
                'phone_formatted' => $formattedPhone
            ]);

            return $formattedPhone;
        } catch (\Exception $e) {
            Log::error('getParticipantPhone - Error', [
                'identity_id' => $identity->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '52')) {
            $phone = substr($phone, 2);
        }

        if (strlen($phone) == 10) {
            return '52' . $phone;
        }

        if (strlen($phone) == 12 && str_starts_with($phone, '52')) {
            return $phone;
        }

        Log::warning('formatPhoneNumber - Formato inesperado', [
            'phone_cleaned' => $phone,
            'length' => strlen($phone)
        ]);

        return $phone;
    }

    private function buildNotificationMessage(WorkOrder $workorder, TaskParticipant $participant): string
    {
        $senderName = 'Un usuario'; // Valor por defecto

        try {
            if (!$workorder->de) {
                Log::error('buildNotificationMessage - Relación "de" NO está cargada', [
                    'workorder_id' => $workorder->id,
                    'de_id' => $workorder->de_id,
                ]);
                $workorder->load('de.firebirdUser');
            }

            if ($workorder->de) {
                Log::info('buildNotificationMessage - Obteniendo datos del remitente', [
                    'de_id' => $workorder->de_id,
                    'de_firebird_user_clave' => $workorder->de->firebird_user_clave ?? 'N/A',
                    'de_firebird_tb_tabla' => $workorder->de->firebird_tb_tabla ?? 'N/A',
                    'de_firebird_tb_clave' => $workorder->de->firebird_tb_clave ?? 'N/A',
                    'de_firebird_empresa' => $workorder->de->firebird_empresa ?? 'N/A',
                ]);

                // 🔥 INTENTO 1: Obtener desde TB (tablas dinámicas)
                if ($workorder->de->firebird_tb_clave && $workorder->de->firebird_empresa) {
                    $senderTbData = $workorder->de->getTbData();

                    if ($senderTbData) {
                        $nombre = trim($senderTbData->NOMBRE ?? '');
                        $apPat = trim($senderTbData->AP_PAT_ ?? '');
                        $apMat = trim($senderTbData->AP_MAT_ ?? '');

                        $senderName = trim("{$nombre} {$apPat} {$apMat}");

                        if (!empty($senderName)) {
                            Log::info('buildNotificationMessage - Nombre obtenido desde TB', [
                                'sender_name' => $senderName,
                                'source' => 'TB dinámicas'
                            ]);
                        }
                    }
                }

                // 🔥 INTENTO 2: Si no hay datos en TB, usar USUARIOS.NOMBRE
                if (empty($senderName) || $senderName === 'Un usuario') {
                    if ($workorder->de->firebirdUser) {
                        $nombreUsuarios = trim($workorder->de->firebirdUser->NOMBRE ?? '');

                        if (!empty($nombreUsuarios)) {
                            $senderName = $nombreUsuarios;

                            Log::info('buildNotificationMessage - Nombre obtenido desde USUARIOS', [
                                'sender_name' => $senderName,
                                'source' => 'USUARIOS.NOMBRE'
                            ]);
                        }
                    } else {
                        Log::warning('buildNotificationMessage - firebirdUser no está cargado', [
                            'de_id' => $workorder->de_id,
                            'relationLoaded' => $workorder->de->relationLoaded('firebirdUser')
                        ]);
                    }
                }

                // 🔥 VALIDACIÓN FINAL
                if (empty($senderName) || $senderName === 'Un usuario') {
                    Log::warning('buildNotificationMessage - No se pudo obtener nombre del remitente', [
                        'de_id' => $workorder->de_id,
                        'has_tb_clave' => !empty($workorder->de->firebird_tb_clave),
                        'has_firebirdUser' => $workorder->de->firebirdUser ? 'YES' : 'NO',
                        'usuarios_nombre' => $workorder->de->firebirdUser->NOMBRE ?? 'N/A'
                    ]);
                    $senderName = 'Un usuario';
                }
            } else {
                Log::error('buildNotificationMessage - workorder->de es null', [
                    'workorder_id' => $workorder->id,
                    'de_id' => $workorder->de_id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('buildNotificationMessage - Error al obtener nombre del remitente', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'workorder_id' => $workorder->id,
                'de_id' => $workorder->de_id,
            ]);
        }

        // 🔥 CALCULAR EN QUÉ PÁGINA ESTÁ ESTE WORKORDER
        $perPage = 15;
        $identityId = $participant->user_id;

        $excludedWorkorderIds = \App\Models\MailboxItem::where('user_id', $identityId)
            ->whereIn('folder', ['spam', 'trash', 'drafts'])
            ->pluck('workorder_id')
            ->toArray();

        $position = \App\Models\WorkOrder::query()
            ->where(function ($w) use ($identityId) {
                $w->where('para_id', $identityId)
                    ->orWhereHas('taskParticipants', function ($p) use ($identityId) {
                        $p->where('user_id', $identityId)
                            ->where('role', 'receptor');
                    });
            })
            ->where('de_id', '!=', $identityId)
            ->when(count($excludedWorkorderIds) > 0, function ($q) use ($excludedWorkorderIds) {
                $q->whereNotIn('id', $excludedWorkorderIds);
            })
            ->where('id', '>', $workorder->id)
            ->count();

        $pageNumber = (int) ceil(($position + 1) / $perPage);

        $link = config('app.frontend_url') . "/pages/mailbox/mensajes/{$pageNumber}/{$workorder->id}";

        $message = "📧 *Nueva tarea asignada*\n\n";
        $message .= "👤 *De:* {$senderName}\n";
        $message .= "📋 *Asunto:* {$workorder->titulo}\n";

        if ($participant->role === 'cc') {
            $message .= "🎯 *Rol:* (CC)\n";
        } elseif ($participant->role === 'bcc') {
            $message .= "🎯 *Rol:* (BCC)\n";
        }

        if ($workorder->descripcion) {
            $cleanText = trim(strip_tags($workorder->descripcion));
            $limit = 100;

            if (mb_strlen($cleanText) > $limit) {
                $preview = mb_substr($cleanText, 0, $limit) . '...';
            } else {
                $preview = $cleanText;
            }

            $message .= "\n*Descripción:*\n{$preview}\n";
        }

        $message .= "\n🔗 *Ver tarea completa:*\n{$link}";

        return $message;
    }
}