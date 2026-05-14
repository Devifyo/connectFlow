<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaceVideo extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new Scopes\TenantScope);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
