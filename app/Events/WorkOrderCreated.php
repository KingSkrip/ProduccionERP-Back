<?php

namespace App\Events;

use App\Models\WorkOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // 👈 CAMBIO
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkorderCreated implements ShouldBroadcastNow // 👈 CAMBIO
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $workorder;
    public $recipientIds;

    public function __construct(WorkOrder $workorder, array $recipientIds = [])
    {
        $this->workorder = $workorder->load([
            'de.firebirdUser',
            'para.firebirdUser',
            'status',
            'taskParticipants.user.firebirdUser',
            'attachments',
        ]);

        $this->recipientIds = $recipientIds;
    }

    public function broadcastOn(): array
    {
        $channels = [];

        foreach ($this->recipientIds as $identityId) {
            $channels[] = new PrivateChannel("user.{$identityId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'workorder.created';
    }

    public function broadcastWith(): array
    {
        return [
            'workorder' => $this->workorder,
            'type' => $this->workorder->type,
            'message' => 'Nuevo ' . ($this->workorder->type === 'Task' ? 'task' : 'mensaje') . ' recibido',
        ];
    }
}