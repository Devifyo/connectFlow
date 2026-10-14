<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\EmailTemplate;
use App\Models\Message;
use App\Models\User;
use App\Support\IdHash;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Emails a group member the latest two group messages they haven't seen yet.
 * Messages already covered by a previous email are never sent again.
 */
class SendGroupUnseenEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PREVIEW_COUNT = 2;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $recipientId,
        public int $conversationId,
    ) {}

    public function handle(): void
    {
        $recipient = User::withoutGlobalScopes()->find($this->recipientId);
        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        if (!$recipient || !$conversation || $conversation->type !== 'group' || !$recipient->is_active) {
            return;
        }

        $membership = DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->first();
        if (!$membership) {
            return;
        }

        $unseen = Message::where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $recipient->id)
            ->where('type', '!=', 'system')
            // Unseen = no read receipt; ignore anything from before they joined.
            ->where('created_at', '>=', $membership->created_at)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('message_reads')
                ->whereColumn('message_reads.message_id', 'messages.id')
                ->where('message_reads.user_id', $this->recipientId))
            ->when($membership->last_notified_message_id, fn ($q) => $q->where('id', '>', $membership->last_notified_message_id))
            ->with('sender:id,name', 'attachments:id,message_id')
            ->orderByDesc('id');

        $total = (clone $unseen)->count();
        if ($total === 0) {
            return;
        }

        $latest = $unseen->limit(self::PREVIEW_COUNT)->get()->reverse()->values();
        $newest = $latest->last();

        $lines = $latest->map(function (Message $m) {
            $text = $m->body ?: ($m->attachments->isNotEmpty() ? '📎 Attachment' : '');
            return '<strong>' . e($m->sender?->name ?? 'Someone') . ':</strong> ' . e(Str::limit($text, 200));
        });
        if ($total > $latest->count()) {
            $lines->push('<em>+' . ($total - $latest->count()) . ' more unread message' . ($total - $latest->count() === 1 ? '' : 's') . '</em>');
        }

        $rendered = EmailTemplate::render('new-message', [
            'app_name' => config('app.name', 'PitchFlow'),
            'recipient_name' => e($recipient->name),
            'sender_name' => e(($newest->sender?->name ?? 'Someone') . ' (' . $conversation->title . ')'),
            'sender_initial' => strtoupper(substr($conversation->title ?: 'G', 0, 1)),
            'message_preview' => $lines->join('<br>'),
            'conversation_id' => IdHash::encode($conversation->id),
            'app_url' => 'https://pitchflow.devifyo.cloud',
            'year' => date('Y'),
        ]);

        // Mark as notified even if the template is missing, so we don't retry the same messages forever.
        DB::table('conversation_participants')
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->update(['last_notified_message_id' => $newest->id]);

        $to = $recipient->getNotificationEmailAddress();
        if (!$rendered || !$to) {
            return;
        }

        Mail::html($rendered['body'], function ($mail) use ($to, $rendered) {
            $mail->to($to)->subject($rendered['subject']);
        });
    }
}
