<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $recipientId,
        public int $conversationId,
        public string $userName,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("messages.{$this->recipientId}")];
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_name' => $this->userName,
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }
}
