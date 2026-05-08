<?php

namespace App\Http\Controllers;

use App\Events\NewMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function conversations()
    {
        $user = auth()->user();
        if (!$user->tenant_id) return response()->json([]);

        $conversations = $user->conversations()
            ->with(['latestMessage.sender', 'participants' => function ($q) use ($user) {
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

                return [
                    'id' => $conversation->id,
                    'user' => $other ? ['id' => $other->id, 'name' => $other->name] : null,
                    'latest_message' => $conversation->latestMessage ? [
                        'body' => $conversation->latestMessage->body,
                        'sender_name' => $conversation->latestMessage->sender?->name ?? 'Unknown',
                        'created_at' => $conversation->latestMessage->created_at->toIso8601String(),
                        'is_mine' => $conversation->latestMessage->user_id === $user->id,
                    ] : null,
                    'unread' => $unread,
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

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->orderByDesc('created_at')
            ->cursorPaginate(50);

        return response()->json($messages);
    }

    public function send(Request $request, Conversation $conversation)
    {
        $request->validate(['body' => 'required|string|max:5000']);

        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $message = $conversation->messages()->create([
            'user_id' => $user->id,
            'body' => $request->body,
        ]);

        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
        ]);

        $message->load('sender:id,name');

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }

        return response()->json($message);
    }

    public function markRead(Conversation $conversation)
    {
        $user = auth()->user();
        if (!$conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $conversation->participants()->updateExistingPivot($user->id, [
            'last_read_at' => now(),
        ]);

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
}
