<?php

namespace App\Events;

use App\Models\MailsReply;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow; // 👈 CAMBIO
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MailReplyCreated implements ShouldBroadcastNow // 👈 CAMBIO
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $reply;
    public $workorder;
    public $recipientIds;

    public function __construct(MailsReply $reply, array $recipientIds = [])
    {
        $this->reply = $reply->load([
            'user.firebirdUser',
            'attachments',
        ]);

        $this->workorder = $reply->workorder->load([
            'de.firebirdUser',
            'para.firebirdUser',
            'status',
            'taskParticipants.user.firebirdUser',
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
        return 'mail.reply.created';
    }

    public function broadcastWith(): array
    {
        return [
            'reply' => $this->reply,
            'workorder' => $this->workorder,
            'message' => 'Nueva respuesta recibida',
        ];
    }
}