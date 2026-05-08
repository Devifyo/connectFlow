<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UserPresenceChanged implements ShouldBroadcastNow
{
    public function __construct(
        private int $recipientId,
        public int $user_id,
        public string $status,
        public ?string $last_active_at,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("messages.{$this->recipientId}")];
    }

    public function broadcastAs(): string
    {
        return 'user.presence';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user_id,
            'status' => $this->status,
            'last_active_at' => $this->last_active_at,
        ];
    }
}
