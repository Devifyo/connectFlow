<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceOverride extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'manual_hours' => 'decimal:2',
        'show_status_to_member' => 'boolean',
        'show_note_to_member' => 'boolean',
    ];
}
