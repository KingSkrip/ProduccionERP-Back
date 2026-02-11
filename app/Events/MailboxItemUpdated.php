<?php

namespace App\Events;

use App\Models\MailboxItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MailboxItemUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $mailboxItem;
    public $action;

    public function __construct(MailboxItem $mailboxItem, string $action)
    {
        Log::info('🔥🔥🔥 MailboxItemUpdated CONSTRUCTOR', [
            'mailbox_item_id' => $mailboxItem->id,
            'user_id' => $mailboxItem->user_id,
            'action' => $action,
        ]);

        $this->mailboxItem = $mailboxItem;
        $this->action = $action;
    }

    public function broadcastOn(): array
    {
        $channel = "user.{$this->mailboxItem->user_id}";

        Log::info('🔥🔥🔥 broadcastOn() LLAMADO', [
            'channel' => $channel,
        ]);

        return [
            new PrivateChannel($channel),
        ];
    }

    public function broadcastAs(): string
    {
        Log::info('🔥🔥🔥 broadcastAs() LLAMADO');
        return 'mailbox.updated';
    }

    public function broadcastWith(): array
    {
        Log::info('🔥🔥🔥 broadcastWith() LLAMADO');

        return [
            'mailbox_item' => $this->mailboxItem,
            'action' => $this->action,
            'workorder_id' => $this->mailboxItem->workorder_id,
        ];
    }
}