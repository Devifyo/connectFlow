<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeLog extends Model
{
    protected $guarded = [];
    protected $primaryKey = 'log_id';

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\TenantScope);
    }
}
