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

];
