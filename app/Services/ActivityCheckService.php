<?php

namespace App\Services;

use App\Models\ActivityCheck;
use App\Models\EmailTemplate;
use App\Models\Tenant;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Silent activity checks during a punched-in session. Nothing is shown to the member: at each
 * check the server looks at the last real input the app reported, and punches the member out
 * automatically if there has been none for too long.
 *
 * Algorithm
 * ---------
 * - Minimum per attendance day: one check per hour of the member's daily minimum, rounded up
 *   (6h minimum => at least 6 checks).
 * - Planned checks: at punch-in, that many check times are scattered at random over the time
 *   still expected today. No fixed slots or spacing, so the next check cannot be guessed; the
 *   first comes a random 5-20 min after punch-in and no two are closer than a few minutes.
 * - At each check, with "idle" = time since the last input, and both limits drawn at random for
 *   that check (active ~7-12 min, inactive ~15-25 min):
 *     idle <= active limit       -> passed
 *     idle <= inactive limit     -> passed, plus a follow-up check at a random 5-15 min
 *     idle  > inactive limit     -> missed: automatic punch-out right then (time counts until the punch-out)
 * - Gone quiet: once a session has had no input for a random 15-25 min, the next check is pulled
 *   in to a random moment within 10 min, so inactivity is caught in roughly 15-35 min.
 * - Re-verify: even when active, each session is closed at a random 45-60 min after punch-in, so
 *   the member has to punch in (and verify their face) again. Each session then carries its share
 *   of the day's checks, at least one, placed at random inside it.
 * - Overtime: once the planned checks are used up, a new check is placed at a random 45-90 min
 *   interval. Follow-ups and overtime count towards a daily cap of twice the minimum.
 * - Everything runs on the server every minute, so closing or reloading the app cannot skip a check.
 */
class ActivityCheckService
{
    private function cfg(string $key, $default)
    {
        return config("attendance.activity_checks.{$key}", $default);
    }

    public function enabledFor(User $user): bool
    {
        if (!$this->cfg('enabled', true)) return false;
        if (!($user->activity_checks_enabled ?? true)) return false;
        $tenant = Tenant::find($user->tenant_id);
        return (bool) ($tenant?->activity_checks_enabled ?? false);
    }

    /** Independent of activity checks: either feature can be on without the other. */
    public function reverifyEnabledFor(User $user): bool
    {
        if (!$this->cfg('enabled', true)) return false;
        if (!($user->activity_reverify_enabled ?? true)) return false;
        return (bool) (Tenant::find($user->tenant_id)?->activity_reverify_enabled ?? false);
    }

    public function requiredPerDay(User $user): int
    {
        return max(1, (int) ceil((float) ($user->min_hours_per_day ?: 8)));
    }

    public function dailyCap(User $user): int
    {
        $min = $this->requiredPerDay($user);
        return max($min * 2, $min + 2);
    }

    public function countForDay(int $userId, string $date): int
    {
        return ActivityCheck::where('user_id', $userId)->where('date', $date)
            ->where('status', '!=', 'cancelled')->count();
    }

    /** Called right after a member punches in. */
    public function scheduleForSession(TimeLog $log): void
    {
        $user = $log->user;
        if (!$user) return;
        $checksOn = $this->enabledFor($user);
        $reverifyOn = $this->reverifyEnabledFor($user);
        if (!$checksOn && !$reverifyOn) return;

        // Punching in is itself activity. When checks are switched on mid-session, start from now so
        // the time before (when nothing was being tracked) isn't counted as inactivity.
        $base = $log->login_time->gt(now()->subMinute()) ? $log->login_time->copy() : now();
        $forcedOut = $log->forced_out_at;
        if (!$forcedOut && $reverifyOn) {
            [$lo, $hi] = $this->cfg('reverify_after_minutes', [45, 60]);
            $forcedOut = $base->copy()->addSeconds(mt_rand($lo * 60, $hi * 60));
        }
        $log->forceFill(['activity_checks' => true, 'last_activity_at' => $log->last_activity_at ?? $base, 'forced_out_at' => $forcedOut])->saveQuietly();
        if (!$checksOn) return; // re-punch only: no random checks for this member

        $date = $log->date->format('Y-m-d');
        $needed = max(0, $this->requiredPerDay($user) - $this->countForDay($user->id, $date));
        if ($needed === 0) {
            $this->topUp($log);
            return;
        }

        $workedToday = TimeLog::where('user_id', $user->id)->where('date', $date)
            ->where('log_id', '!=', $log->log_id)->whereNotNull('logout_time')->sum('total_hours');
        $remainingMinutes = max(30, (int) round(((float) $user->min_hours_per_day - (float) $workedToday) * 60));

        if ($forcedOut) {
            // The session ends at $forcedOut, so place this session's share of the day's checks
            // (at least one) at random inside it. Every re-punch schedules the next share, so a
            // full day still gets the daily minimum.
            $sessionMinutes = max(10, (int) floor($base->diffInSeconds($forcedOut, true) / 60) - 2);
            $share = max(1, (int) round($needed * $sessionMinutes / $remainingMinutes));
            $share = min($share, $needed, max(1, intdiv($sessionMinutes, (int) $this->cfg('min_gap_minutes', 8))));
            foreach ($this->randomOffsets($share, $sessionMinutes, true) as $offsetSeconds) {
                $this->create($log, 'random', $base->copy()->addSeconds($offsetSeconds));
            }
            return;
        }

        foreach ($this->randomOffsets($needed, $remainingMinutes) as $offsetSeconds) {
            $this->create($log, 'random', $base->copy()->addSeconds($offsetSeconds));
        }
    }

    /**
     * $count check times (seconds after punch-in) scattered at random over $windowMinutes, with no
     * fixed slots or spacing: the gaps vary freely, so the next check cannot be predicted. Only two
     * guards: not right after punch-in (random 5-20 min), and no two checks within min_gap minutes.
     */
    private function randomOffsets(int $count, int $windowMinutes, bool $strictEnd = false): array
    {
        $minGap = (int) $this->cfg('min_gap_minutes', 8) * 60;
        $firstMax = (int) $this->cfg('first_check_max_minutes', 20) * 60;
        if ($strictEnd) $firstMax = min($firstMax, max(300, $windowMinutes * 60 - $count * $minGap));
        $start = mt_rand(min((int) $this->cfg('first_check_min_minutes', 5) * 60, $firstMax), $firstMax);
        $end = max($start + $count * $minGap, $windowMinutes * 60);

        $best = [];
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $points = [];
            for ($i = 0; $i < $count; $i++) $points[] = mt_rand($start, $end);
            sort($points);
            $ok = true;
            for ($i = 1; $i < $count; $i++) {
                if ($points[$i] - $points[$i - 1] < $minGap) { $ok = false; break; }
            }
            if ($ok) return $points;
            $best = $points;
        }
        // Very crowded window: push apart just enough to respect the minimum gap.
        for ($i = 1; $i < $count; $i++) {
            if ($best[$i] - $best[$i - 1] < $minGap) $best[$i] = $best[$i - 1] + $minGap + mt_rand(0, 300);
        }
        return $best;
    }

    private function create(TimeLog $log, string $reason, CarbonInterface $dueAt): ActivityCheck
    {
        return ActivityCheck::create([
            'tenant_id' => $log->tenant_id,
            'user_id' => $log->user_id,
            'time_log_id' => $log->log_id,
            'date' => $log->date->format('Y-m-d'),
            'reason' => $reason,
            'due_at' => $dueAt,
            'status' => 'pending',
        ]);
    }

    private function capReached(TimeLog $log): bool
    {
        return $this->countForDay($log->user_id, $log->date->format('Y-m-d')) >= $this->dailyCap($log->user);
    }

    /** Overtime: when no check is waiting, schedule the next one at a random interval. */
    public function topUp(TimeLog $log): void
    {
        $hasPending = ActivityCheck::where('time_log_id', $log->log_id)->where('status', 'pending')->exists();
        if ($hasPending || $this->capReached($log)) return;
        if ($log->forced_out_at) {
            // Re-verify sessions are short: one check per session is enough, the next punch-in
            // carries the next one. Only fill a session that has had none yet.
            $hadOne = ActivityCheck::where('time_log_id', $log->log_id)->where('status', '!=', 'cancelled')->exists();
            if ($hadOne) return;
        }
        if ($log->forced_out_at && $log->forced_out_at->gt(now()->addMinutes(7))) {
            // Session ends at its re-verify time: keep the check inside it, at a random moment.
            $this->create($log, 'overtime', now()->addSeconds(mt_rand(300, (int) now()->diffInSeconds($log->forced_out_at, true) - 120)));
            return;
        }
        if ($log->forced_out_at) return;
        $minutes = mt_rand((int) $this->cfg('overtime_min_minutes', 45), (int) $this->cfg('overtime_max_minutes', 90));
        $this->create($log, 'overtime', now()->addMinutes($minutes));
    }

    /** Record input reported by the app (never moves backwards, never before punch-in). */
    public function recordActivity(TimeLog $log, CarbonInterface $at): void
    {
        if ($at->gt(now())) $at = now();
        if ($at->lt($log->login_time)) return;
        if ($log->last_activity_at && $log->last_activity_at->gte($at)) return;
        $log->forceFill(['last_activity_at' => $at])->saveQuietly();
    }

    public function cancelForSession(int $logId): void
    {
        ActivityCheck::where('time_log_id', $logId)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /** Runs every minute: evaluate due checks and top up overtime checks. */
    public function process(): array
    {
        $stats = ['passed' => 0, 'followups' => 0, 'auto_out' => 0, 'topped_up' => 0];

        $due = ActivityCheck::where('status', 'pending')->where('due_at', '<=', now())->orderBy('due_at')->get();
        foreach ($due as $check) {
            $log = TimeLog::with('user')->find($check->time_log_id);
            if (!$log || $log->logout_time || !$log->user || !$this->enabledFor($log->user)) {
                $check->update(['status' => 'cancelled']);
                continue;
            }

            $last = $log->last_activity_at ?? $log->login_time;
            $idleMinutes = $last->diffInSeconds(now(), true) / 60;
            // Thresholds are drawn fresh for every check, so there is no fixed idle time to game.
            [$aLo, $aHi] = $this->cfg('active_within_minutes', [7, 12]);
            [$iLo, $iHi] = $this->cfg('inactive_after_minutes', [15, 25]);
            $activeWithin = mt_rand($aLo * 60, $aHi * 60) / 60;
            $inactiveAfter = max($activeWithin + 1, mt_rand($iLo * 60, $iHi * 60) / 60);
            $check->update(['shown_at' => now()]); // evaluated at

            if ($idleMinutes <= $activeWithin) {
                $check->update(['status' => 'passed', 'responded_at' => $last]);
                $stats['passed']++;
            } elseif ($idleMinutes <= $inactiveAfter) {
                $check->update(['status' => 'passed', 'responded_at' => $last]);
                $stats['passed']++;
                if (!$this->capReached($log)) {
                    $this->create($log, 'followup', now()->addMinutes(mt_rand(
                        (int) $this->cfg('followup_min_minutes', 5), (int) $this->cfg('followup_max_minutes', 15))));
                    $stats['followups']++;
                }
            } else {
                $check->update(['status' => 'missed']);
                if ($this->autoPunchOut($log, $check, $last)) $stats['auto_out']++;
            }
        }

        $open = TimeLog::whereNull('logout_time')->where('activity_checks', true)->with('user')->get();
        foreach ($open as $log) {
            if (!$log->user) continue;

            // Scheduled re-verification: close the session even though the member is active.
            if ($log->forced_out_at && $log->forced_out_at->lte(now()) && $this->reverifyEnabledFor($log->user)) {
                if ($this->closeSession($log, $log->forced_out_at, 'reverify')) {
                    $stats['reverify'] = ($stats['reverify'] ?? 0) + 1;
                    Log::info("[activity-check] user {$log->user_id} re-verify punch-out (log {$log->log_id}) at {$log->forced_out_at}");
                }
                continue;
            }

            // Gone quiet (no input, or the app closed): pull the next check in to a random moment in
            // the next few minutes so inactivity is caught soon, still at a time that can't be guessed.
            if (!$this->enabledFor($log->user)) continue; // re-punch only: no activity checks

            if ($this->pullInIfQuiet($log)) $stats['pulled_in'] = ($stats['pulled_in'] ?? 0) + 1;

            $before = ActivityCheck::where('time_log_id', $log->log_id)->count();
            $this->topUp($log);
            if (ActivityCheck::where('time_log_id', $log->log_id)->count() > $before) $stats['topped_up']++;
        }

        return $stats;
    }

    /**
     * Once a session has been quiet longer than its quiet limit (a random 15-25 min, fixed for each
     * quiet stretch so it can't be probed), make sure a check is due within a random 0-10 min.
     */
    private function pullInIfQuiet(TimeLog $log): bool
    {
        $last = $log->last_activity_at ?? $log->login_time;
        [$lo, $hi] = $this->cfg('quiet_limit_minutes', [15, 25]);
        $limit = $lo + (crc32($log->log_id . '|' . $last->timestamp) % max(1, $hi - $lo + 1));
        if ($last->diffInSeconds(now(), true) < $limit * 60) return false;

        $latest = now()->addMinutes((int) $this->cfg('quiet_pull_in_max_minutes', 10));
        $next = ActivityCheck::where('time_log_id', $log->log_id)->where('status', 'pending')->orderBy('due_at')->first();
        if ($next && $next->due_at->lte($latest)) return false;

        $due = now()->addSeconds(mt_rand(0, (int) $this->cfg('quiet_pull_in_max_minutes', 10) * 60));
        if ($next) {
            $next->update(['due_at' => $due]);
        } elseif (!$this->capReached($log)) {
            $this->create($log, 'idle', $due);
        } else {
            return false;
        }
        return true;
    }

    /** Close the session at the moment of the automatic punch-out; time counts up to it. */
    public function autoPunchOut(TimeLog $log, ActivityCheck $check, CarbonInterface $lastActivity): bool
    {
        if (!$this->closeSession($log, now(), 'no_activity')) return false;
        Log::info("[activity-check] user {$log->user_id} auto punched out (log {$log->log_id}, check {$check->id}, last activity {$lastActivity})");
        $this->notify($log->fresh(), $check);
        return true;
    }

    /** Close an open session at $at (never before punch-in) and cancel its pending checks. */
    private function closeSession(TimeLog $log, CarbonInterface $at, string $reason): bool
    {
        $closed = DB::transaction(function () use ($log, $at, $reason) {
            $log = TimeLog::where('log_id', $log->log_id)->lockForUpdate()->first();
            if (!$log || $log->logout_time) return false;
            $out = $at->copy();
            if ($out->lt($log->login_time)) $out = $log->login_time->copy();
            if ($out->gt(now())) $out = now();
            $log->update([
                'logout_time' => $out,
                'total_hours' => round(abs($out->floatDiffInHours($log->login_time)), 4),
                'auto_punch_out_reason' => $reason,
            ]);
            return true;
        });
        if ($closed) $this->cancelForSession($log->log_id);
        return $closed;
    }

    private function notify(TimeLog $log, ActivityCheck $check): void
    {
        try {
            $user = $log->user;
            $to = $user?->getNotificationEmailAddress();
            if (!$to) return;
            $tz = config('attendance.timezone', 'Asia/Kolkata');
            $rendered = EmailTemplate::render('auto-punched-out', [
                'app_name' => config('app.name', 'PitchFlow'),
                'app_url' => config('app.url'),
                'user_name' => $user->name,
                'check_time' => $check->due_at->copy()->setTimezone($tz)->format('h:i A'),
                'out_time' => $log->logout_time->copy()->setTimezone($tz)->format('h:i A'),
                'punch_in_time' => $log->login_time->copy()->setTimezone($tz)->format('h:i A'),
                'inactive_minutes' => (string) (int) floor(($log->last_activity_at ?? $log->login_time)->diffInSeconds($log->logout_time, true) / 60),
                'year' => date('Y'),
            ]);
            if (!$rendered) return;
            Mail::html($rendered['body'], fn ($m) => $m->to($to)->subject($rendered['subject']));
        } catch (\Throwable $e) {
            Log::warning('[activity-check] notify failed: ' . $e->getMessage());
        }
    }
}
