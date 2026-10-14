<?php

namespace App\Http\Controllers;

use App\Events\ChatEvent;
use App\Events\MessagesRead;
use App\Events\NewMessage;
use App\Events\UserPresenceChanged;
use App\Events\UserTyping;
use App\Jobs\SendGroupUnseenEmail;
use App\Jobs\SendOfflineMessageEmail;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MessageController extends Controller
{
    private const UNSEEN_GROUP_EMAIL_DELAY_SECONDS = 10;

    public function conversations()
    {
        $user = auth()->user();
        if (!$user->tenant_id) return response()->json([]);

        $conversations = $user->conversations()
            ->with(['latestMessage.sender', 'latestMessage.attachments', 'participants' => function ($q) use ($user) {
                $q->where('user_id', '!=', $user->id)->with(['positions' => fn ($q) => $q->select('id', 'title', 'sort_order')->orderBy('sort_order')]);
            }])
            ->get()
            ->map(function ($conversation) use ($user) {
                $isGroup = $conversation->isGroup();
                $other = $isGroup ? null : $conversation->participants->first();
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
                    'type' => $conversation->type,
                    'is_group' => $isGroup,
                    'title' => $conversation->title,
                    'created_by' => $conversation->created_by,
                    'member_count' => $conversation->participants->count() + 1,
                    'can_send' => (bool) ($pivot->can_send ?? true),
                    'user' => $other ? [
                        'id' => $other->id,
                        'name' => $other->name,
                        'profile_picture_url' => $other->profile_picture_url,
                        'designation' => $other->designation,
                        'positions' => $other->positions->map(fn ($p) => ['id' => $p->id, 'title' => $p->title]),
                        'presence_status' => $this->resolvePresence($other),
                        'last_active_at' => $other->last_active_at?->toIso8601String(),
                    ] : null,
                    'latest_message' => $conversation->latestMessage ? [
                        'type' => $conversation->latestMessage->type,
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

        // all=1 returns every active teammate (used by "everyone" in the group member picker).
        $all = $request->boolean('all');
        $query = $request->get('q', '');
        if (!$all && strlen($query) < 1) return response()->json([]);

        $users = User::where('id', '!=', $user->id)
            ->where('is_active', true)
            ->when(!$all, fn ($q) => $q->where('name', 'like', "%{$query}%")->limit(10))
            ->select('id', 'name', 'designation', 'profile_picture')
            ->with(['positions' => fn ($q) => $q->select('id', 'title', 'sort_order')->orderBy('sort_order')])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'designation' => $u->designation,
                'profile_picture_url' => $u->profile_picture_url,
                'positions' => $u->positions->map(fn ($p) => ['id' => $p->id, 'title' => $p->title]),
            ]);

        return response()->json($users);
    }

    public function startOrFind(Request $request)
    {
        $request->validate(['user_id' => 'required|integer']);

        $user = auth()->user();
        $targetId = $request->user_id;

        $target = User::where('id', $targetId)->where('tenant_id', $user->tenant_id)->first();
        if (!$target) return response()->json(['error' => 'User not found'], 404);

        $existing = Conversation::where('type', 'direct')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
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

        // Seen ticks only make sense 1:1; groups show sent state only.
        $otherLastRead = null;
        if (!$conversation->isGroup()) {
            $otherParticipant = $conversation->participants()
                ->where('user_id', '!=', $user->id)
                ->first();
            $otherLastRead = $otherParticipant?->pivot?->last_read_at;
        }

        $messages = $conversation->messages()
            ->with(['sender:id,name,profile_picture', 'attachments', 'reactions.user:id,name', 'pinner:id,name'])
            ->orderByDesc('created_at')
            ->cursorPaginate(30);

        // The group owner sees "seen by x/y" on every message.
        $seen = $conversation->isOwner($user) ? $this->seenCounts($conversation, $messages->getCollection()) : [];

        $messages->through(fn (Message $m) => $m->toChatArray() + ($seen[$m->id] ?? []));

        return response()->json([
            ...$messages->toArray(),
            'other_last_read' => $otherLastRead ? \Carbon\Carbon::parse($otherLastRead)->toIso8601String() : null,
        ]);
    }

    public function send(Request $request, Conversation $conversation)
    {
        $request->validate([
            'body' => 'nullable|string|max:5000',
            'attachments' => 'nullable|array|max:9',
            'attachments.*' => 'file|max:51200|mimes:jpg,jpeg,png,gif,webp,svg,mp4,webm,mov,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,csv',
            'mentions' => 'nullable|array|max:50',
            'mentions.*' => 'integer',
        ]);

        if (!$request->body && !$request->hasFile('attachments')) {
            return response()->json(['error' => 'Message or attachment required'], 422);
        }

        $user = auth()->user();
        $membership = $conversation->participants()->where('user_id', $user->id)->first();
        if (!$membership) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($conversation->isGroup() && !$membership->pivot->can_send) {
            return response()->json(['error' => 'The group owner has restricted you from sending messages here.'], 403);
        }

        // Only keep mentions of people who are actually in this conversation.
        $mentions = collect($request->input('mentions', []))
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === $user->id)
            ->unique()
            ->values();
        if ($conversation->isGroup() && $request->body && preg_match('/(^|\s)@everyone\b/i', $request->body)) {
            // @everyone mentions every other member of the group.
            $mentions = $conversation->participants()->where('user_id', '!=', $user->id)->pluck('user_id')->values();
        } elseif ($mentions->isNotEmpty()) {
            $mentions = $conversation->participants()->whereIn('user_id', $mentions)->pluck('user_id')->values();
        }

        $message = $conversation->messages()->create([
            'user_id' => $user->id,
            'body' => $request->body,
            'mentions' => $mentions->isNotEmpty() ? $mentions->all() : null,
        ]);

        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->storeAttachment($message, $file);
            }
        }

        if (!session()->has('impersonator_id')) {
            $conversation->participants()->updateExistingPivot($user->id, [
                'last_read_at' => now(),
            ]);
        }

        $message->load('sender:id,name,profile_picture', 'attachments');

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }

        $recipientIds = $conversation->participants()
            ->where('user_id', '!=', $user->id)
            ->pluck('user_id');

        if ($conversation->isGroup()) {
            $this->queueGroupUnseenEmails($conversation, $recipientIds);
        } else {
            foreach ($recipientIds as $recipientId) {
                $recipient = User::withoutGlobalScopes()->find($recipientId);
                if ($recipient && $this->resolvePresence($recipient) === 'offline') {
                    SendOfflineMessageEmail::dispatch(
                        $recipientId,
                        $user->id,
                        $request->body ?? '📎 Attachment',
                        $conversation->id,
                        $message->id,
                    );
                }
            }
        }

        $extra = $conversation->isOwner($user) ? ($this->seenCounts($conversation, collect([$message]))[$message->id] ?? []) : [];

        return response()->json($message->toChatArray() + $extra);
    }

    /**
     * Every member who still hasn't seen the message after a few seconds (online or offline)
     * gets one email with the latest two unseen messages. One pending email per member per
     * window keeps a burst of messages from sending a burst of emails.
     */
    private function queueGroupUnseenEmails(Conversation $conversation, $recipientIds): void
    {
        $delay = self::UNSEEN_GROUP_EMAIL_DELAY_SECONDS;

        foreach ($recipientIds as $recipientId) {
            if (\Cache::add("group-unseen-email:{$conversation->id}:{$recipientId}", 1, now()->addSeconds($delay))) {
                SendGroupUnseenEmail::dispatch($recipientId, $conversation->id)->delay(now()->addSeconds($delay));
            }
        }
    }

    /**
     * [message_id => ['seen_count' => n, 'audience_count' => m]] for non-system messages.
     * Audience = current members (other than the sender) who were in the group when it was sent.
     */
    private function seenCounts(Conversation $conversation, $messages): array
    {
        $messages = $messages->reject(fn ($m) => $m->type === 'system');
        if ($messages->isEmpty()) return [];

        $members = $conversation->participants()->get()
            ->map(fn ($m) => ['id' => $m->id, 'joined' => \Carbon\Carbon::parse($m->pivot->created_at)]);

        $receipts = \DB::table('message_reads')
            ->whereIn('message_id', $messages->pluck('id'))
            ->get(['message_id', 'user_id'])
            ->groupBy('message_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->flip());

        $out = [];
        foreach ($messages as $m) {
            $audience = $members->filter(fn ($x) => $x['id'] !== $m->user_id && $x['joined']->lte($m->created_at));
            $readers = $receipts[$m->id] ?? collect();
            $out[$m->id] = [
                'audience_count' => $audience->count(),
                'seen_count' => $audience->filter(fn ($x) => $readers->has($x['id']))->count(),
            ];
        }

        return $out;
    }

    public function react(Request $request, Message $message)
    {
        $request->validate(['emoji' => 'required|string|max:32']);

        $user = auth()->user();
        $conversation = $message->conversation;
        if (!$conversation || !$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $existing = MessageReaction::where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $request->emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            MessageReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $request->emoji,
            ]);
        }

        $message->unsetRelation('reactions');
        $reactions = $message->reactionSummary();

        $this->broadcastToOthers($conversation, $user->id, 'reaction', [
            'message_id' => $message->id,
            'reactions' => $reactions,
        ]);

        return response()->json(['message_id' => $message->id, 'reactions' => $reactions]);
    }

    public function togglePin(Message $message)
    {
        $user = auth()->user();
        $conversation = $message->conversation;
        $membership = $conversation?->participants()->where('user_id', $user->id)->first();
        if (!$membership) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        if ($conversation->isGroup() && !$membership->pivot->can_send && !$conversation->isOwner($user)) {
            return response()->json(['error' => 'You are not allowed to pin messages in this group.'], 403);
        }
        if ($message->type === 'system') {
            return response()->json(['error' => 'System messages cannot be pinned.'], 422);
        }

        $message->forceFill($message->pinned_at
            ? ['pinned_at' => null, 'pinned_by' => null]
            : ['pinned_at' => now(), 'pinned_by' => $user->id]
        )->save();
        $message->unsetRelation('pinner');

        $payload = $message->toChatArray();
        $this->broadcastToOthers($conversation, $user->id, 'pin', ['message' => $payload]);

        return response()->json($payload);
    }

    /** Group owner: who has seen a message, and when. */
    public function seenBy(Message $message)
    {
        $user = auth()->user();
        $conversation = $message->conversation;
        if (!$conversation || !$conversation->isOwner($user)) {
            return response()->json(['error' => 'Only the group owner can see this.'], 403);
        }

        $receipts = \DB::table('message_reads')->where('message_id', $message->id)->pluck('read_at', 'user_id');

        $members = $conversation->participants()
            ->where('user_id', '!=', $message->user_id)
            // Skip people who joined after the message was sent.
            ->where('conversation_participants.created_at', '<=', $message->created_at)
            ->get();

        $seen = [];
        $notSeen = [];
        foreach ($members as $m) {
            $row = ['id' => $m->id, 'name' => $m->name, 'profile_picture_url' => $m->profile_picture_url];
            if (isset($receipts[$m->id])) {
                $seen[] = $row + ['seen_at' => \Carbon\Carbon::parse($receipts[$m->id])->toIso8601String()];
            } else {
                $notSeen[] = $row;
            }
        }

        usort($seen, fn ($a, $b) => strcmp($a['seen_at'] ?? '', $b['seen_at'] ?? ''));

        return response()->json(['seen' => $seen, 'not_seen' => $notSeen]);
    }

    public function pinned(Conversation $conversation)
    {
        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $pinned = $conversation->messages()
            ->whereNotNull('pinned_at')
            ->with(['sender:id,name,profile_picture', 'attachments', 'reactions.user:id,name', 'pinner:id,name'])
            ->orderByDesc('pinned_at')
            ->get()
            ->map(fn (Message $m) => $m->toChatArray());

        return response()->json($pinned);
    }

    private function broadcastToOthers(Conversation $conversation, int $actorId, string $action, array $payload): void
    {
        $ids = $conversation->participants()->where('user_id', '!=', $actorId)->pluck('user_id')->all();
        if (!$ids) return;

        try {
            broadcast(new ChatEvent($ids, $action, $conversation->id, $payload));
        } catch (\Throwable $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }
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

        $etag = md5($attachment->stored_path . $attachment->updated_at);
        if (request()->header('If-None-Match') === '"' . $etag . '"') {
            return response('', 304);
        }

        return $disk->response($attachment->stored_path, $attachment->original_name, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => '"' . $etag . '"',
        ]);
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
        if (session()->has('impersonator_id')) {
            return response()->json(['ok' => true]);
        }

        $user = auth()->user();
        $membership = $conversation->participants()->where('user_id', $user->id)->first();
        if (!$membership) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $now = now();
        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_at' => $now,
        ]);

        if ($conversation->isGroup()) {
            // Record a receipt for every message that became seen now (since last read, or since joining).
            $since = $membership->pivot->last_read_at ?? $membership->pivot->created_at;
            $ids = $conversation->messages()
                ->where('user_id', '!=', $user->id)
                ->where('type', '!=', 'system')
                ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->pluck('id');

            $already = $ids->isEmpty() ? collect() : \DB::table('message_reads')
                ->where('user_id', $user->id)->whereIn('message_id', $ids)->pluck('message_id');
            $newIds = $ids->diff($already)->values();

            foreach ($newIds->chunk(500) as $chunk) {
                \DB::table('message_reads')->insertOrIgnore(
                    $chunk->map(fn ($id) => ['message_id' => $id, 'user_id' => $user->id, 'read_at' => $now])->values()->all()
                );
            }

            // Let the owner's open chat bump its "seen by" counters live.
            if ($newIds->isNotEmpty() && $conversation->created_by && (int) $conversation->created_by !== (int) $user->id) {
                try {
                    broadcast(new ChatEvent([$conversation->created_by], 'seen', $conversation->id, [
                        'message_ids' => $newIds->all(),
                        'user' => ['id' => $user->id, 'name' => $user->name],
                        'seen_at' => $now->toIso8601String(),
                    ]));
                } catch (\Throwable $e) {}
            }

            return response()->json(['ok' => true]);
        }

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
        if (session()->has('impersonator_id')) {
            return response()->json(['ok' => true]);
        }

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
        if (session()->has('impersonator_id')) {
            return response()->json(['ok' => true]);
        }

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
        $seconds = abs(now()->diffInSeconds($user->last_active_at));
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
            ->where('type', 'direct')
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
