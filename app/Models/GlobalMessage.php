<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class GlobalMessage extends Model
{
    protected $fillable = [
        'tenant_id',
        'sender_id',
        'title',
        'body',
        'priority',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipients()
    {
        return $this->belongsToMany(User::class, 'global_message_recipients')
            ->withPivot('read_at', 'dismissed_at')
            ->withTimestamps();
    }

    public function reactions()
    {
        return $this->hasMany(AnnouncementReaction::class, 'global_message_id');
    }

    public function comments()
    {
        return $this->hasMany(AnnouncementComment::class, 'global_message_id');
    }
}
