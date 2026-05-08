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

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\TenantScope);
    }
}
