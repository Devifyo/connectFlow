<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Shortly after the 3 AM IST attendance rollover: remind anyone still punched in from yesterday.
Schedule::command('attendance:remind-open-sessions')
    ->dailyAt('03:10')
    ->timezone(config('attendance.timezone', 'Asia/Kolkata'))
    ->withoutOverlapping();

// Complete uploads abandoned by a phone (locked screen, closed tab) so admin sees them promptly.
Schedule::command('face-videos:finalize-stale')->everyTenMinutes()->withoutOverlapping();
