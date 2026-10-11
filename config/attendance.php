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
    | Keep the untouched recording next to the converted mp4 (as .raw.webm) for diagnostics and
    | recovery. Costs disk; turn off once phone recordings are known to convert cleanly.
    */
    'keep_raw_face_videos' => env('KEEP_RAW_FACE_VIDEOS', true),

];
