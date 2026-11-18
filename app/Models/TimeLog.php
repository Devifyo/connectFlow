<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class TimeLog extends Model
{
    protected $guarded = [];
    protected $primaryKey = 'log_id';

    protected $casts = [
        'login_time' => 'datetime',
        'logout_time' => 'datetime',
        'date' => 'date',
        'total_hours' => 'decimal:4',
        'punch_in_lat' => 'decimal:7',
        'punch_in_lng' => 'decimal:7',
        'punch_out_lat' => 'decimal:7',
        'punch_out_lng' => 'decimal:7',
    ];

    /**
     * Calendar day a moment belongs to for attendance: the business-timezone date, with the day
     * rolling over at `attendance.day_starts_at_hour` (3 AM by default) rather than midnight.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function attendanceDate(CarbonInterface $moment): string
    {
        return $moment->copy()
            ->setTimezone(config('attendance.timezone'))
            ->subHours((int) config('attendance.day_starts_at_hour', 0))
            ->toDateString();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\TenantScope);
    }

    /**
     * Hours worked on one attendance day, honouring an admin day adjustment.
     *
     * When the adjustment carries manual hours, those hours replace every session that
     * existed when the adjustment was saved. Sessions started after the adjustment keep
     * counting on top of it, so a member who punches in again after an admin edit sees
     * the clock continue from the adjusted total.
     */
    public static function dayHours(iterable $logs, ?\App\Models\AttendanceOverride $override, ?CarbonInterface $now = null): float
    {
        $now = $now ?? \Carbon\Carbon::now('UTC');
        $hasManual = $override && $override->manual_hours !== null;
        $cutoff = $hasManual ? $override->updated_at : null;

        $hours = $hasManual ? (float) $override->manual_hours : 0.0;

        foreach ($logs as $log) {
            if ($cutoff && $log->login_time && $log->login_time->lte($cutoff)) {
                continue;
            }
            if ($log->logout_time) {
                $hours += abs((float) $log->total_hours);
            } elseif ($log->login_time) {
                $hours += abs($now->floatDiffInHours($log->login_time));
            }
        }

        return $hours;
    }
}
