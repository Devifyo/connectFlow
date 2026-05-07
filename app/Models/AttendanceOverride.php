<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceOverride extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'manual_hours' => 'decimal:2',
    ];
}
