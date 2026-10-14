<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];

    protected $casts = [
        'mentions' => 'array',
        'pinned_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScope(\App\Models\Scopes\TenantScope::class);
    }

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class);
    }

    public function reactions()
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function pinner()
    {
        return $this->belongsTo(User::class, 'pinned_by')->withoutGlobalScope(\App\Models\Scopes\TenantScope::class);
    }

    /**
     * Reactions grouped by emoji: [{emoji, count, users: [{id, name}]}].
     */
    public function reactionSummary(): array
    {
        $this->loadMissing('reactions.user:id,name');

        return $this->reactions
            ->groupBy('emoji')
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'users' => $group->map(fn ($r) => ['id' => $r->user_id, 'name' => $r->user?->name])->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * Shape used by the chat UI for history, send responses and pinned lists.
     */
    public function toChatArray(): array
    {
        $this->loadMissing('sender:id,name,profile_picture', 'attachments', 'pinner:id,name');

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'user_id' => $this->user_id,
            'type' => $this->type ?? 'text',
            'body' => $this->body,
            'mentions' => $this->mentions ?? [],
            'sender' => $this->sender ? [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
                'profile_picture_url' => $this->sender->profile_picture_url,
            ] : null,
            'attachments' => $this->attachments->values()->toArray(),
            'reactions' => $this->reactionSummary(),
            'pinned_at' => $this->pinned_at?->toIso8601String(),
            'pinned_by' => $this->pinner ? ['id' => $this->pinner->id, 'name' => $this->pinner->name] : null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
