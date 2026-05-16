<?php

namespace App\Http\Controllers;

use App\Events\GlobalMessagePosted;
use App\Jobs\SendAnnouncementEmail;
use App\Models\AnnouncementComment;
use App\Models\AnnouncementReaction;
use App\Models\GlobalMessage;
use App\Models\User;
use Illuminate\Http\Request;

class GlobalMessageController extends Controller
{
    public function send(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'priority' => 'in:normal,urgent',
            'recipient_ids' => 'required|array|min:1',
            'recipient_ids.*' => 'integer|exists:users,id',
        ]);

        $user = $request->user();

        $recipientIds = User::whereIn('id', $validated['recipient_ids'])
            ->where('tenant_id', $user->tenant_id)
            ->pluck('id');

        if ($recipientIds->isEmpty()) {
            return response()->json(['error' => 'No valid recipients found'], 422);
        }

        $message = GlobalMessage::create([
            'tenant_id' => $user->tenant_id,
            'sender_id' => $user->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'priority' => $validated['priority'] ?? 'normal',
        ]);

        $pivotData = $recipientIds->mapWithKeys(fn ($id) => [$id => ['created_at' => now(), 'updated_at' => now()]]);
        $message->recipients()->attach($pivotData);

        broadcast(new GlobalMessagePosted($message, $recipientIds->toArray()));

        SendAnnouncementEmail::dispatch($message->id, $recipientIds->toArray());

        return response()->json([
            'message' => 'Global message sent successfully',
            'recipients_count' => $recipientIds->count(),
        ]);
    }

    public function pending(Request $request)
    {
        $user = $request->user();

        $messages = $user->globalMessages()
            ->wherePivotNull('dismissed_at')
            ->with('sender:id,name,profile_picture')
            ->orderByDesc('global_messages.created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'title' => $msg->title,
                'body' => $msg->body,
                'priority' => $msg->priority,
                'sender_name' => $msg->sender?->name ?? 'Admin',
                'sender_picture' => $msg->sender?->profile_picture_url,
                'created_at' => $msg->created_at->toIso8601String(),
                'read_at' => $msg->pivot->read_at,
            ]);

        return response()->json($messages);
    }

    public function dismiss(Request $request, int $id)
    {
        $user = $request->user();

        $user->globalMessages()->updateExistingPivot($id, [
            'read_at' => $user->globalMessages()->wherePivot('global_message_id', $id)->first()?->pivot->read_at ?? now(),
            'dismissed_at' => now(),
        ]);

        return response()->json(['status' => 'dismissed']);
    }

    public function myHistory(Request $request)
    {
        $user = $request->user();

        $messages = $user->globalMessages()
            ->with('sender:id,name,profile_picture')
            ->orderByDesc('global_messages.created_at')
            ->get()
            ->map(fn ($msg) => [
                'id' => $msg->id,
                'title' => $msg->title,
                'body' => $msg->body,
                'priority' => $msg->priority,
                'sender_name' => $msg->sender?->name ?? 'Admin',
                'sender_picture' => $msg->sender?->profile_picture_url,
                'created_at' => $msg->created_at->toIso8601String(),
                'read_at' => $msg->pivot->read_at,
                'dismissed_at' => $msg->pivot->dismissed_at,
            ]);

        return response()->json($messages);
    }

    public function history(Request $request)
    {
        $user = $request->user();

        $messages = GlobalMessage::where('sender_id', $user->id)
            ->withCount('recipients')
            ->withCount(['recipients as read_count' => fn ($q) => $q->whereNotNull('global_message_recipients.read_at')])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($messages);
    }

    public function recipients(Request $request, int $id)
    {
        $user = $request->user();

        $message = GlobalMessage::where('sender_id', $user->id)->findOrFail($id);

        $recipients = $message->recipients()
            ->select('users.id', 'users.name', 'users.email', 'users.designation', 'users.profile_picture')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'email' => $r->email,
                'designation' => $r->designation,
                'profile_picture_url' => $r->profile_picture_url,
                'read_at' => $r->pivot->read_at ? \Carbon\Carbon::parse($r->pivot->read_at)->toIso8601String() : null,
                'dismissed_at' => $r->pivot->dismissed_at ? \Carbon\Carbon::parse($r->pivot->dismissed_at)->toIso8601String() : null,
            ]);

        return response()->json($recipients);
    }

    public function teamMembers(Request $request)
    {
        $user = $request->user();

        $query = User::where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->where('is_active', true)
            ->with(['positions:id,title']);

        if ($request->filled('position_id')) {
            $query->whereHas('positions', fn ($q) => $q->where('positions.id', $request->position_id));
        }

        if ($request->filled('designation')) {
            $query->where('designation', $request->designation);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('designation', 'like', "%{$search}%");
            });
        }

        $members = $query->select('id', 'name', 'email', 'designation', 'profile_picture')
            ->orderBy('name')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'designation' => $m->designation,
                'profile_picture_url' => $m->profile_picture_url,
                'positions' => $m->positions->pluck('title'),
            ]);

        return response()->json($members);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'priority' => 'in:normal,urgent',
        ]);

        $user = $request->user();
        $message = GlobalMessage::where('sender_id', $user->id)->findOrFail($id);

        $message->update([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'priority' => $validated['priority'] ?? $message->priority,
        ]);

        return response()->json(['status' => 'updated']);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $request->user();

        $message = GlobalMessage::where('sender_id', $user->id)->findOrFail($id);

        $message->recipients()->detach();
        $message->reactions()->delete();
        $message->comments()->delete();
        $message->delete();

        return response()->json(['status' => 'deleted']);
    }

    public function react(Request $request, int $id)
    {
        $request->validate(['emoji' => 'required|string|max:10']);

        $user = $request->user();

        $exists = AnnouncementReaction::where('global_message_id', $id)
            ->where('user_id', $user->id)
            ->where('emoji', $request->emoji)
            ->first();

        if ($exists) {
            $exists->delete();
            return response()->json(['status' => 'removed']);
        }

        AnnouncementReaction::create([
            'global_message_id' => $id,
            'user_id' => $user->id,
            'emoji' => $request->emoji,
        ]);

        return response()->json(['status' => 'added']);
    }

    public function comments(Request $request, int $id)
    {
        $comments = AnnouncementComment::where('global_message_id', $id)
            ->with('user:id,name,profile_picture')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'body' => $c->body,
                'user_id' => $c->user_id,
                'user_name' => $c->user?->name ?? 'Unknown',
                'user_picture' => $c->user?->profile_picture_url,
                'created_at' => $c->created_at->toIso8601String(),
            ]);

        return response()->json($comments);
    }

    public function addComment(Request $request, int $id)
    {
        $request->validate(['body' => 'required|string|max:2000']);

        $user = $request->user();

        $comment = AnnouncementComment::create([
            'global_message_id' => $id,
            'user_id' => $user->id,
            'body' => $request->body,
        ]);

        return response()->json([
            'id' => $comment->id,
            'body' => $comment->body,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_picture' => $user->profile_picture_url,
            'created_at' => $comment->created_at->toIso8601String(),
        ]);
    }

    public function reactions(Request $request, int $id)
    {
        $reactions = AnnouncementReaction::where('global_message_id', $id)
            ->select('emoji', \DB::raw('count(*) as count'))
            ->groupBy('emoji')
            ->get();

        $userReactions = AnnouncementReaction::where('global_message_id', $id)
            ->where('user_id', $request->user()->id)
            ->pluck('emoji');

        return response()->json([
            'reactions' => $reactions,
            'user_reactions' => $userReactions,
        ]);
    }

    public function reactionsDetail(Request $request, int $id)
    {
        $reactions = AnnouncementReaction::where('global_message_id', $id)
            ->with('user:id,name,profile_picture')
            ->orderBy('emoji')
            ->get()
            ->map(fn ($r) => [
                'emoji' => $r->emoji,
                'user_name' => $r->user?->name ?? 'Unknown',
                'user_picture' => $r->user?->profile_picture_url,
            ]);

        return response()->json($reactions);
    }
}
