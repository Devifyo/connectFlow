<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing('attachments');
    }

    public function broadcastOn(): array
    {
        return $this->message->conversation->participants
            ->pluck('id')
            ->reject(fn ($id) => $id === $this->message->user_id)
            ->map(fn ($id) => new PrivateChannel("messages.{$id}"))
            ->toArray();
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'user_id' => $this->message->user_id,
            'sender_name' => $this->message->sender?->name ?? 'Unknown',
            'body' => $this->message->body,
            'attachments' => $this->message->attachments->map(fn ($a) => [
                'id' => $a->id,
                'original_name' => $a->original_name,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'type' => $a->type,
                'width' => $a->width,
                'height' => $a->height,
                'url' => $a->url,
            ])->toArray(),
            'created_at' => $this->message->created_at->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}
