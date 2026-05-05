<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
    protected $guarded = [];
    protected $primaryKey = 'bid_id';

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\TenantScope);
    }
}
