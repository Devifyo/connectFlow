<?php

namespace App\Jobs;

use App\Models\EmailTemplate;
use App\Models\GlobalMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendAnnouncementEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public int $messageId,
        public array $recipientIds,
    ) {}

    public function handle(): void
    {
        $message = GlobalMessage::withoutGlobalScopes()->find($this->messageId);
        if (!$message) return;

        $sender = User::withoutGlobalScopes()->find($message->sender_id);
        if (!$sender) return;

        $recipients = User::withoutGlobalScopes()->whereIn('id', $this->recipientIds)->get();

        $appUrl = config('app.url', 'https://pitchflow.devifyo.cloud');

        $senderAvatar = $sender->profile_picture
            ? '<img src="' . $appUrl . \App\Support\IdHash::emailAvatarUrl($sender->id) . '" width="36" height="36" style="width:36px;height:36px;border-radius:50%;object-fit:cover;display:block;" />'
            : '<div style="width:36px;height:36px;border-radius:50%;background-color:#84cc16;text-align:center;line-height:36px;font-size:15px;font-weight:700;color:#020617;">' . strtoupper(substr($sender->name, 0, 1)) . '</div>';

        $bodyHtml = $this->formatBodyForEmail($message->body);

        $priorityBadge = $message->priority === 'urgent'
            ? '<p style="margin:0 0 12px;"><span style="display:inline-block;padding:4px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;background-color:#7f1d1d;color:#fca5a5;border-radius:4px;">⚡ Urgent</span></p>'
            : '';

        foreach ($recipients as $recipient) {
            $rendered = EmailTemplate::render('new-announcement', [
                'app_name' => config('app.name', 'PitchFlow'),
                'title' => $message->title,
                'sender_name' => $sender->name,
                'sender_avatar' => $senderAvatar,
                'body_html' => $bodyHtml,
                'priority_badge' => $priorityBadge,
                'app_url' => $appUrl,
                'year' => date('Y'),
            ]);

            if (!$rendered) continue;

            Mail::html($rendered['body'], function ($mail) use ($recipient, $rendered) {
                $mail->to($recipient->getNotificationEmailAddress())->subject($rendered['subject']);
            });
        }
    }

    private function formatBodyForEmail(string $html): string
    {
        $html = preg_replace('/<p>/', '<p style="margin:0 0 8px;font-size:14px;color:#e2e8f0;line-height:1.6;">', $html);
        $html = preg_replace('/<strong>/', '<strong style="color:#f1f5f9;font-weight:600;">', $html);
        $html = preg_replace('/<em>/', '<em style="color:#e2e8f0;">', $html);
        $html = preg_replace('/<ul>/', '<ul style="margin:8px 0;padding-left:20px;color:#e2e8f0;">', $html);
        $html = preg_replace('/<ol>/', '<ol style="margin:8px 0;padding-left:20px;color:#e2e8f0;">', $html);
        $html = preg_replace('/<li>/', '<li style="margin:4px 0;font-size:14px;line-height:1.5;">', $html);
        $html = preg_replace('/<blockquote>/', '<blockquote style="margin:12px 0;padding:10px 16px;border-left:3px solid #84cc16;background-color:#1e293b;color:#94a3b8;font-style:italic;">', $html);
        $html = preg_replace('/<h[1-3]>/', '<p style="margin:12px 0 6px;font-size:16px;font-weight:700;color:#f1f5f9;">', $html);
        $html = preg_replace('/<\/h[1-3]>/', '</p>', $html);
        $html = preg_replace('/<span[^>]*data-type="mention"[^>]*>/', '<span style="color:#84cc16;font-weight:600;">', $html);
        return $html;
    }
}
