<?php

namespace App\Models;

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
    public static function attendanceDate(\Carbon\CarbonInterface $moment): string
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
}
