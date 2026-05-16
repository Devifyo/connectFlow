<?php

namespace App\Events;

use App\Models\GlobalMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GlobalMessagePosted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GlobalMessage $message,
        public array $recipientIds,
    ) {}

    public function broadcastOn(): array
    {
        return collect($this->recipientIds)
            ->map(fn ($id) => new PrivateChannel("messages.{$id}"))
            ->toArray();
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'title' => $this->message->title,
            'body' => $this->message->body,
            'priority' => $this->message->priority,
            'sender_name' => $this->message->sender?->name ?? 'Admin',
            'sender_picture' => $this->message->sender?->profile_picture_url,
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'global.message';
    }
}
