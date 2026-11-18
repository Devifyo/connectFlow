<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\TimeLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * After the attendance day rolls over (3 AM IST), members who are still punched in from the
 * previous day are reminded once to punch out.
 */
class RemindOpenSessions extends Command
{
    protected $signature = 'attendance:remind-open-sessions {--dry-run : List who would be emailed without sending}';
    protected $description = 'Email members whose previous-day session was never punched out';

    public function handle(): int
    {
        $today = TimeLog::attendanceDate(Carbon::now('UTC'));
        $open = TimeLog::whereNull('logout_time')->with('user')->get()
            ->filter(fn ($log) => TimeLog::attendanceDate($log->login_time) < $today);

        $sent = 0;
        foreach ($open as $log) {
            $user = $log->user;
            if (!$user || !$user->is_active) continue;

            $key = "forgot-punchout:{$log->log_id}";
            if ($this->option('dry-run')) {
                $this->line("would email {$user->email} for log {$log->log_id} (in {$log->login_time})");
                continue;
            }
            if (!Cache::add($key, 1, 3 * 86400)) continue;

            $to = $user->getNotificationEmailAddress();
            if (!$to) continue;

            $in = $log->login_time->copy()->setTimezone(config('attendance.timezone', 'Asia/Kolkata'));
            $rendered = EmailTemplate::render('forgot-punch-out', [
                'app_name' => config('app.name', 'PitchFlow'),
                'app_url' => config('app.url'),
                'user_name' => $user->name,
                'punch_in_time' => $in->format('h:i A, d M Y'),
                'year' => date('Y'),
            ]);
            if (!$rendered) continue;

            Mail::html($rendered['body'], function ($mail) use ($to, $rendered) {
                $mail->to($to)->subject($rendered['subject']);
            });
            $sent++;
            Log::info("[punch-email] forgot-punch-out reminder sent to user {$user->id} (log {$log->log_id})");
        }

        $this->info("Open sessions from previous days: {$open->count()}, emails sent: {$sent}");
        return self::SUCCESS;
    }
}
