<?php

namespace App\Http\Controllers;

use App\Events\ChatEvent;
use App\Events\NewMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GroupChatController extends Controller
{
    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user->canCreateGroups()) {
            return response()->json(['error' => 'You are not allowed to create group chats.'], 403);
        }

        $request->validate([
            'title' => 'required|string|max:100',
            'member_ids' => 'required|array|min:1|max:200',
            'member_ids.*' => 'integer',
        ]);

        $memberIds = $this->tenantUserIds($request->member_ids)->reject(fn ($id) => $id === $user->id);
        if ($memberIds->isEmpty()) {
            return response()->json(['error' => 'Pick at least one team member.'], 422);
        }

        $conversation = DB::transaction(function () use ($user, $request, $memberIds) {
            $conversation = Conversation::create([
                'tenant_id' => $user->tenant_id,
                'type' => 'group',
                'title' => trim($request->title),
                'created_by' => $user->id,
            ]);

            $conversation->participants()->attach($user->id, ['role' => 'owner', 'can_send' => true, 'last_read_at' => now()]);
            $conversation->participants()->attach(
                $memberIds->mapWithKeys(fn ($id) => [$id => ['role' => 'member', 'can_send' => true]])->all()
            );

            return $conversation;
        });

        $this->systemMessage($conversation, $user, "{$user->name} created the group \"{$conversation->title}\"");
        $this->announceJoined($conversation, $user, $memberIds);

        return response()->json(['conversation_id' => $conversation->id]);
    }

    public function show(Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeMember($conversation, $user);

        $members = $conversation->participants()
            ->with(['positions' => fn ($q) => $q->select('positions.id', 'title', 'sort_order')])
            ->get()
            ->sortBy(fn ($m) => [$m->pivot->role === 'owner' ? 0 : 1, $m->name])
            ->values()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'profile_picture_url' => $m->profile_picture_url,
                'positions' => $m->positions->map(fn ($p) => ['id' => $p->id, 'title' => $p->title]),
                'role' => $m->pivot->role,
                'can_send' => (bool) $m->pivot->can_send,
            ]);

        return response()->json([
            'id' => $conversation->id,
            'title' => $conversation->title,
            'created_by' => $conversation->created_by,
            'is_owner' => $conversation->isOwner($user),
            'invite_url' => $conversation->isOwner($user) ? $this->inviteUrl($conversation) : null,
            'members' => $members,
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        $request->validate(['title' => 'required|string|max:100']);

        $old = $conversation->title;
        $conversation->update(['title' => trim($request->title)]);

        if ($old !== $conversation->title) {
            $this->systemMessage($conversation, $user, "{$user->name} renamed the group to \"{$conversation->title}\"");
            $this->notify($conversation, $user->id, 'group.updated', ['title' => $conversation->title]);
        }

        return response()->json(['ok' => true, 'title' => $conversation->title, 'system_messages' => $this->posted]);
    }

    public function addMembers(Request $request, Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        $request->validate([
            'user_ids' => 'required|array|min:1|max:200',
            'user_ids.*' => 'integer',
        ]);

        $existing = $conversation->participants()->pluck('user_id');
        $newIds = $this->tenantUserIds($request->user_ids)->diff($existing)->values();
        if ($newIds->isEmpty()) {
            return response()->json(['ok' => true, 'added' => 0]);
        }

        $conversation->participants()->attach(
            $newIds->mapWithKeys(fn ($id) => [$id => ['role' => 'member', 'can_send' => true]])->all()
        );

        $this->announceJoined($conversation, $user, $newIds);
        $this->notify($conversation, $user->id, 'group.members');

        return response()->json(['ok' => true, 'added' => $newIds->count(), 'system_messages' => $this->posted]);
    }

    public function updateMember(Request $request, Conversation $conversation, int $memberId)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        $request->validate(['can_send' => 'required|boolean']);

        if ($memberId === $user->id) {
            return response()->json(['error' => 'The owner can always send messages.'], 422);
        }

        $member = $conversation->participants()->where('user_id', $memberId)->first();
        if (!$member) {
            return response()->json(['error' => 'Not a member of this group.'], 404);
        }

        $conversation->participants()->updateExistingPivot($memberId, ['can_send' => $request->boolean('can_send')]);
        $this->notify($conversation, $user->id, 'group.members');

        return response()->json(['ok' => true]);
    }

    public function setSendPermissionForAll(Request $request, Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        $request->validate(['can_send' => 'required|boolean']);

        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $user->id)
            ->update(['can_send' => $request->boolean('can_send'), 'updated_at' => now()]);

        $this->systemMessage($conversation, $user, $request->boolean('can_send')
            ? "{$user->name} allowed everyone to send messages"
            : "{$user->name} set the group so only they can send messages");
        $this->notify($conversation, $user->id, 'group.members');

        return response()->json(['ok' => true, 'system_messages' => $this->posted]);
    }

    public function createInviteLink(Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        // Always issue a fresh token, so "reset link" invalidates anything shared before.
        $conversation->forceFill(['invite_token' => Str::random(32)])->save();

        return response()->json(['invite_url' => $this->inviteUrl($conversation)]);
    }

    public function disableInviteLink(Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        $conversation->forceFill(['invite_token' => null])->save();

        return response()->json(['invite_url' => null]);
    }

    public function join(string $token)
    {
        $user = auth()->user();

        // TenantScope already limits this to the user's own tenant.
        $conversation = Conversation::where('type', 'group')->where('invite_token', $token)->first();
        if (!$conversation || (int) $conversation->tenant_id !== (int) $user->tenant_id || !$user->is_active) {
            return response()->json(['error' => 'This invite link is invalid or has been turned off.'], 404);
        }

        if ($conversation->participants()->where('user_id', $user->id)->exists()) {
            return response()->json(['conversation_id' => $conversation->id, 'already_member' => true]);
        }

        $conversation->participants()->attach($user->id, ['role' => 'member', 'can_send' => true]);

        $this->systemMessage($conversation, $user, "{$user->name} joined the group");
        $this->notify($conversation, $user->id, 'group.members');

        return response()->json(['conversation_id' => $conversation->id, 'already_member' => false]);
    }

    /** "Mel joined the group · added by Sunil" (one line for several people). */
    private function announceJoined(Conversation $conversation, User $actor, $userIds): void
    {
        $names = User::whereIn('id', $userIds)->orderBy('name')->pluck('name');
        if ($names->isEmpty()) return;

        $who = $names->count() > 5
            ? $names->take(5)->join(', ') . ' and ' . ($names->count() - 5) . ' others'
            : $names->join(', ', ' and ');

        $this->systemMessage($conversation, $actor, "{$who} joined the group · added by {$actor->name}");
    }

    private function inviteUrl(Conversation $conversation): ?string
    {
        return $conversation->invite_token ? url('/dashboard?join=' . $conversation->invite_token) : null;
    }

    public function removeMember(Conversation $conversation, int $memberId)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        if ($memberId === $user->id) {
            return response()->json(['error' => 'Use "Leave group" to remove yourself.'], 422);
        }

        $member = $conversation->participants()->where('user_id', $memberId)->first();
        if (!$member) {
            return response()->json(['error' => 'Not a member of this group.'], 404);
        }

        $conversation->participants()->detach($memberId);

        $this->broadcast([$memberId], 'group.removed', $conversation->id, ['title' => $conversation->title]);
        $this->systemMessage($conversation, $user, "{$member->name} was removed from the group by {$user->name}");
        $this->notify($conversation, $user->id, 'group.members');

        return response()->json(['ok' => true, 'system_messages' => $this->posted]);
    }

    public function leave(Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeMember($conversation, $user);

        $wasOwner = $conversation->isOwner($user);
        $conversation->participants()->detach($user->id);

        $next = $conversation->participants()->orderBy('conversation_participants.created_at')->first();
        if (!$next) {
            $conversation->delete();
            return response()->json(['ok' => true]);
        }

        $text = "{$user->name} left the group";
        if ($wasOwner) {
            // Hand ownership to the longest-standing member so the group stays manageable.
            $conversation->update(['created_by' => $next->id]);
            $conversation->participants()->updateExistingPivot($next->id, ['role' => 'owner', 'can_send' => true]);
            $text .= " · {$next->name} is now the owner";
        }

        $this->systemMessage($conversation, $user, $text);
        $this->notify($conversation, $user->id, 'group.members');

        return response()->json(['ok' => true]);
    }

    public function destroy(Conversation $conversation)
    {
        $user = auth()->user();
        $this->authorizeOwner($conversation, $user);

        $memberIds = $conversation->participants()->where('user_id', '!=', $user->id)->pluck('user_id')->all();
        $id = $conversation->id;
        $title = $conversation->title;

        $conversation->delete();

        $this->broadcast($memberIds, 'group.removed', $id, ['title' => $title, 'deleted' => true]);

        return response()->json(['ok' => true]);
    }

    private function authorizeMember(Conversation $conversation, User $user): void
    {
        abort_unless($conversation->isGroup(), 404);
        abort_unless($conversation->participants()->where('user_id', $user->id)->exists(), 403);
    }

    private function authorizeOwner(Conversation $conversation, User $user): void
    {
        $this->authorizeMember($conversation, $user);
        abort_unless($conversation->isOwner($user), 403, 'Only the group owner can do this.');
    }

    /** Active users from the current tenant among the given ids. */
    private function tenantUserIds(array $ids)
    {
        return User::whereIn('id', $ids)
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /** System lines posted during this request, returned to the actor (broadcasts skip the sender). */
    private array $posted = [];

    /** Post a centred "system" line into the group; it also makes the group appear for new members. */
    private function systemMessage(Conversation $conversation, User $actor, string $text): void
    {
        $message = $conversation->messages()->create([
            'user_id' => $actor->id,
            'type' => 'system',
            'body' => $text,
        ]);

        $conversation->participants()->updateExistingPivot($actor->id, ['last_read_at' => now()]);
        $this->posted[] = $message->toChatArray();

        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }
    }

    private function notify(Conversation $conversation, int $actorId, string $action, array $payload = []): void
    {
        $ids = $conversation->participants()->where('user_id', '!=', $actorId)->pluck('user_id')->all();
        $this->broadcast($ids, $action, $conversation->id, $payload);
    }

    private function broadcast(array $ids, string $action, int $conversationId, array $payload = []): void
    {
        if (!$ids) return;

        try {
            broadcast(new ChatEvent($ids, $action, $conversationId, $payload));
        } catch (\Throwable $e) {
            \Log::warning('Broadcast failed: ' . $e->getMessage());
        }
    }
}
