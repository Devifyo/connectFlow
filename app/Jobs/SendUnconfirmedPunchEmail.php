<?php

namespace App\Jobs;

use App\Models\EmailTemplate;
use App\Models\FaceVideo;
use App\Models\TimeLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails a member whose face was verified for a punch but who left before pressing
 * "Confirm & close", so the punch was never recorded. Dispatched with a delay when such a
 * recording finishes uploading; by the time it runs the member may already have retried, in
 * which case nothing is sent.
 */
class SendUnconfirmedPunchEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Minutes after the recording finished at which each reminder goes out. */
    public const REMINDER_DELAYS = [1 => 10, 2 => 30];
    public const THROTTLE_SECONDS = 3600;

    public function __construct(public int $faceVideoId, public int $attempt = 1) {}

    /** Queue the first reminder for a recording; later reminders chain from the job itself. */
    public static function schedule(int $faceVideoId): void
    {
        static::dispatch($faceVideoId, 1)->delay(now()->addMinutes(self::REMINDER_DELAYS[1]));
    }

    public function handle(): void
    {
        $video = FaceVideo::find($this->faceVideoId);
        if (!$video || $video->time_log_id || !$video->verified) return;
        if (!in_array($video->type, ['punch_in', 'punch_out'], true)) return;

        $user = $video->user;
        if (!$user || !$user->is_active) return;

        // Already completed the same punch after this attempt: nothing to chase.
        $retried = $video->type === 'punch_in'
            ? TimeLog::where('user_id', $user->id)->where('login_time', '>=', $video->created_at)->exists()
            : TimeLog::where('user_id', $user->id)->where('logout_time', '>=', $video->created_at)->exists();
        if ($retried) return;

        // A punch-out reminder only makes sense while a session is still open, and a punch-in
        // reminder only while there is none.
        $hasOpen = TimeLog::where('user_id', $user->id)->whereNull('logout_time')->exists();
        if (($video->type === 'punch_out' && !$hasOpen) || ($video->type === 'punch_in' && $hasOpen)) return;

        // One email per reminder step per member per hour, however many attempts were abandoned.
        if (!Cache::add("punch-email:{$user->id}:{$this->attempt}", 1, self::THROTTLE_SECONDS)) return;

        $to = $user->getNotificationEmailAddress();
        if (!$to) return;

        $label = $video->type === 'punch_in' ? 'punch in' : 'punch out';
        $rendered = EmailTemplate::render('punch-not-marked', [
            'app_name' => config('app.name', 'PitchFlow'),
            'app_url' => config('app.url'),
            'user_name' => $user->name,
            'punch_label' => $label,
            'punch_label_title' => ucfirst($label),
            'year' => date('Y'),
        ]);
        if (!$rendered) return;

        $subject = $this->attempt > 1 ? 'Second reminder — ' . $rendered['subject'] : $rendered['subject'];
        Mail::html($rendered['body'], function ($mail) use ($to, $subject) {
            $mail->to($to)->subject($subject);
        });
        Log::info("[punch-email] unconfirmed {$video->type} reminder #{$this->attempt} sent to user {$user->id} (video {$video->id})");

        // Chain the next reminder; it re-checks everything above, so it is skipped once they punch.
        $next = $this->attempt + 1;
        if (isset(self::REMINDER_DELAYS[$next])) {
            $minutesFromNow = self::REMINDER_DELAYS[$next] - self::REMINDER_DELAYS[$this->attempt];
            static::dispatch($video->id, $next)->delay(now()->addMinutes($minutesFromNow));
        }
    }
}
