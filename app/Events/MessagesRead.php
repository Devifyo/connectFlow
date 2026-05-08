<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $recipientId,
        public int $conversationId,
        public string $readAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("messages.{$this->recipientId}")];
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'read_at' => $this->readAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'messages.read';
    }
}
