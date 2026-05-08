<?php

namespace App\Http\Controllers;

use App\Events\MessagesRead;
use App\Events\NewMessage;
use App\Events\UserPresenceChanged;
use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    public function conversations()
    {
        $user = auth()->user();
        if (!$user->tenant_id) return response()->json([]);

        $conversations = $user->conversations()
            ->with(['latestMessage.sender', 'latestMessage.attachments', 'participants' => function ($q) use ($user) {
                $q->where('user_id', '!=', $user->id);
            }])
            ->get()
            ->map(function ($conversation) use ($user) {
                $other = $conversation->participants->first();
                $pivot = $conversation->pivot;
                $lastRead = $pivot->last_read_at;

                $unread = $conversation->messages()
                    ->where('user_id', '!=', $user->id)
                    ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
                    ->count();

                $otherPivot = $other ? $conversation->participants()
                    ->where('user_id', $other->id)
                    ->first()?->pivot : null;

                return [
                    'id' => $conversation->id,
                    'user' => $other ? [
                        'id' => $other->id,
                        'name' => $other->name,
                        'presence_status' => $this->resolvePresence($other),
                        'last_active_at' => $other->last_active_at?->toIso8601String(),
                    ] : null,
                    'latest_message' => $conversation->latestMessage ? [
                        'body' => $conversation->latestMessage->body ?: $this->attachmentPreviewText($conversation->latestMessage),
                        'sender_name' => $conversation->latestMessage->sender?->name ?? 'Unknown',
                        'created_at' => $conversation->latestMessage->created_at->toIso8601String(),
                        'is_mine' => $conversation->latestMessage->user_id === $user->id,
                    ] : null,
                    'unread' => $unread,
                    'other_last_read' => $otherPivot?->last_read_at ? \Carbon\Carbon::parse($otherPivot->last_read_at)->toIso8601String() : null,
                    'updated_at' => $conversation->latestMessage?->created_at?->toIso8601String() ?? $conversation->created_at->toIso8601String(),
                ];
            })
            ->sortByDesc('updated_at')
            ->values();

        return response()->json($conversations);
    }

    public function searchUsers(Request $request)
    {
        $user = auth()->user();
        if (!$user->tenant_id) return response()->json([]);

        $query = $request->get('q', '');
        if (strlen($query) < 1) return response()->json([]);

        $users = User::where('id', '!=', $user->id)
            ->where('is_active', true)
            ->where('name', 'like', "%{$query}%")
            ->select('id', 'name', 'designation')
            ->limit(10)
            ->get();

        return response()->json($users);
    }

    public function startOrFind(Request $request)
    {
        $request->validate(['user_id' => 'required|integer']);

        $user = auth()->user();
        $targetId = $request->user_id;

        $target = User::where('id', $targetId)->where('tenant_id', $user->tenant_id)->first();
        if (!$target) return response()->json(['error' => 'User not found'], 404);

        $existing = Conversation::whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $targetId))
            ->withCount('participants')
            ->get()
            ->where('participants_count', 2)
            ->first();

        if ($existing) {
            return response()->json(['conversation_id' => $existing->id]);
        }

        $conversation = Conversation::create(['tenant_id' => $user->tenant_id]);
        $conversation->participants()->attach([$user->id, $targetId]);

        return response()->json(['conversation_id' => $conversation->id]);
    }

    public function messages(Conversation $conversation)
    {
        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $otherParticipant = $conversation->participants()
            ->where('user_id', '!=', $user->id)
            ->first();

        $otherLastRead = $otherParticipant?->pivot?->last_read_at;

        $messages = $conversation->messages()
            ->with(['sender:id,name', 'attachments'])
            ->orderByDesc('created_at')
            ->cursorPaginate(50);

        return response()->json([
            ...$messages->toArray(),
            'other_last_read' => $otherLastRead ? \Carbon\Carbon::parse($otherLastRead)->toIso8601String() : null,
        ]);
    }

    public function send(Request $request, Conversation $conversation)
    {
        $request->validate([
            'body' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:25600|mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,mov,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,csv',
        ]);

        if (!$request->body && !$request->hasFile('attachments')) {
            return response()->json(['error' => 'Message or attachment required'], 422);
        }

        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $message = $conversation->messages()->create([
            'user_id' => $user->id,
            'body' => $request->body,
        ]);

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->storeAttachment($message, $file);
            }
        }

        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
        ]);

        $message->load('sender:id,name', 'attachments');

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }

        return response()->json($message);
    }

    public function showAttachment(MessageAttachment $attachment)
    {
        $user = auth()->user();
        $conversation = $attachment->message->conversation;
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            abort(403);
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($attachment->stored_path)) {
            abort(404);
        }

        return $disk->response($attachment->stored_path, $attachment->original_name);
    }

    private function storeAttachment($message, $file): void
    {
        $type = match (true) {
            str_starts_with($file->getMimeType(), 'image/') => 'image',
            str_starts_with($file->getMimeType(), 'video/') => 'video',
            default => 'document',
        };

        $path = $file->store(
            'message-attachments/' . date('Y/m') . '/' . $message->conversation_id,
            'local'
        );

        $attrs = [
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'type' => $type,
        ];

        if ($type === 'image') {
            $dims = @getimagesize($file->getRealPath());
            if ($dims) {
                $attrs['width'] = $dims[0];
                $attrs['height'] = $dims[1];
            }
        }

        $message->attachments()->create($attrs);
    }

    private function attachmentPreviewText($message): string
    {
        $count = $message->attachments->count();
        if ($count === 0) return '';
        $type = $message->attachments->first()->type;
        if ($count === 1) {
            return match ($type) {
                'image' => '📷 Photo',
                'video' => '🎥 Video',
                default => '📎 File',
            };
        }
        return "📎 {$count} files";
    }

    public function markRead(Conversation $conversation)
    {
        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $now = now();
        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_at' => $now,
        ]);

        $otherIds = $conversation->participants()
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id');

        foreach ($otherIds as $otherId) {
            try {
                broadcast(new MessagesRead($otherId, $conversation->id, $now->toIso8601String()));
            } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true]);
    }

    public function typing(Conversation $conversation)
    {
        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $recipientIds = $conversation->participants()
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id');

        foreach ($recipientIds as $recipientId) {
            try {
                broadcast(new UserTyping($recipientId, $conversation->id, $user->name));
            } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true]);
    }

    public function unreadCount()
    {
        $user = auth()->user();
        if (!$user->tenant_id) return response()->json(['count' => 0]);

        $count = 0;
        $conversations = $user->conversations()->with('messages')->get();

        foreach ($conversations as $conversation) {
            $lastRead = $conversation->pivot->last_read_at;
            $count += $conversation->messages()
                ->where('user_id', '!=', $user->id)
                ->when($lastRead, fn ($q) => $q->where('created_at', '>', $lastRead))
                ->count();
        }

        return response()->json(['count' => $count]);
    }

    public function heartbeat(Request $request)
    {
        $user = auth()->user();
        $newStatus = $request->input('status', 'online');
        $oldStatus = $user->presence_status ?? 'offline';

        $user->forceFill([
            'last_active_at' => now(),
            'presence_status' => $newStatus,
        ])->saveQuietly();

        if ($oldStatus !== $newStatus) {
            $this->broadcastPresence($user, $newStatus);
        }

        return response()->json(['ok' => true]);
    }

    public function goOffline()
    {
        $user = auth()->user();
        if (!$user) return response()->json(['ok' => true]);

        $user->forceFill([
            'presence_status' => 'offline',
            'last_active_at' => now(),
        ])->saveQuietly();
        $this->broadcastPresence($user, 'offline');

        return response()->json(['ok' => true]);
    }

    private function resolvePresence($user): string
    {
        if (!$user->last_active_at) {
            return 'offline';
        }
        $seconds = now()->diffInSeconds($user->last_active_at);
        if ($user->presence_status === 'online' && $seconds > 45) {
            return 'offline';
        }
        if ($user->presence_status === 'away' && $seconds > 90) {
            return 'offline';
        }
        return $user->presence_status ?? 'offline';
    }

    private function broadcastPresence($user, string $status): void
    {
        $partnerIds = $user->conversations()
            ->with('participants')
            ->get()
            ->flatMap(fn ($c) => $c->participants->pluck('id'))
            ->reject(fn ($id) => $id === $user->id)
            ->unique();

        foreach ($partnerIds as $partnerId) {
            try {
                broadcast(new UserPresenceChanged(
                    $partnerId,
                    $user->id,
                    $status,
                    $user->last_active_at?->toIso8601String(),
                ));
            } catch (\Throwable $e) {}
        }
    }
}
