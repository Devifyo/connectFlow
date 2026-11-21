<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attendance Timezone
    |--------------------------------------------------------------------------
    |
    | Wall-clock times typed by admins for manual punches are interpreted in
    | this timezone, and attendance times are presented to admins in it.
    | Storage remains UTC.
    |
    */

    'timezone' => env('ATTENDANCE_TIMEZONE', 'Asia/Kolkata'),

    /*
    | Hour (0-23, in the timezone above) at which the attendance day rolls over. With 3, a shift
    | that runs past midnight until 3 AM still belongs to the day it started.
    */
    'day_starts_at_hour' => (int) env('ATTENDANCE_DAY_STARTS_AT_HOUR', 3),

    /*
    | Keep the untouched recording next to the converted mp4 (as .raw.webm) for diagnostics and
    | recovery. Costs disk; turn off once phone recordings are known to convert cleanly.
    */
    'keep_raw_face_videos' => env('KEEP_RAW_FACE_VIDEOS', true),

    /*
    | Silent activity checks while punched in (see App\Services\ActivityCheckService). At least
    | one check per hour of a member's daily minimum, scattered at random. At a check, no input for
    | longer than a randomly drawn limit punches the member out right then. Each tenant
    | can switch this off in admin Settings.
    */
    'activity_checks' => [
        'enabled' => env('ACTIVITY_CHECKS_ENABLED', true),
        // [min, max] minutes; a fresh random value is drawn for every check.
        'active_within_minutes' => [7, 12],
        'inactive_after_minutes' => [15, 25],
        'first_check_min_minutes' => 5,
        'first_check_max_minutes' => 20,
        'min_gap_minutes' => 8,
        // A session quiet for longer than a random value in this range gets a check pulled in to
        // a random moment within quiet_pull_in_max_minutes.
        'quiet_limit_minutes' => [15, 25],
        'quiet_pull_in_max_minutes' => 10,
        // Even when active, close each session at a random point in this range after punch-in.
        'reverify_after_minutes' => [45, 60],
        'overtime_min_minutes' => 45,
        'overtime_max_minutes' => 90,
        'followup_min_minutes' => 5,
        'followup_max_minutes' => 15,
    ],

];
