<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityCheck extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'due_at' => 'datetime',
        'shown_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function timeLog()
    {
        return $this->belongsTo(TimeLog::class, 'time_log_id', 'log_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
