<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Generic chat update (reactions, pins, group membership/settings) pushed to
 * each recipient's private messages channel. The client switches on `action`.
 */
class ChatEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public array $recipientIds,
        public string $action,
        public int $conversationId,
        public array $payload = [],
    ) {}

    public function broadcastOn(): array
    {
        return collect($this->recipientIds)
            ->unique()
            ->map(fn ($id) => new PrivateChannel("messages.{$id}"))
            ->values()
            ->all();
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'conversation_id' => $this->conversationId,
            ...$this->payload,
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.event';
    }
}
