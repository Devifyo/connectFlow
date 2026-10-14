<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\IdHash;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOfflineMessageEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $recipientId,
        public int $senderId,
        public string $messageBody,
        public int $conversationId,
        public ?int $messageId = null,
    ) {}

    public function handle(): void
    {
        $recipient = User::withoutGlobalScopes()->find($this->recipientId);
        $sender = User::withoutGlobalScopes()->find($this->senderId);

        if (!$recipient || !$sender) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()->find($this->conversationId);
        if (!$conversation) {
            return;
        }

        if ($this->resolvePresence($recipient) !== 'offline') {
            return;
        }

        $preview = $conversation->type === 'group'
            ? "In \"{$conversation->title}\": {$this->messageBody}"
            : $this->messageBody;

        $rendered = EmailTemplate::render('new-message', [
            'app_name' => config('app.name', 'PitchFlow'),
            'recipient_name' => $recipient->name,
            'sender_name' => $sender->name,
            'sender_initial' => strtoupper(substr($sender->name, 0, 1)),
            'message_preview' => \Illuminate\Support\Str::limit($preview, 200),
            'conversation_id' => IdHash::encode($this->conversationId),
            'app_url' => 'https://pitchflow.devifyo.cloud',
            'year' => date('Y'),
        ]);

        $to = $recipient->getNotificationEmailAddress();
        if (!$rendered || !$to) {
            return;
        }

        Mail::html($rendered['body'], function ($mail) use ($to, $rendered) {
            $mail->to($to)->subject($rendered['subject']);
        });
    }

    private function resolvePresence(User $user): string
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
}
