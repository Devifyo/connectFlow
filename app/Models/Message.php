<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = [];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScope(\App\Models\Scopes\TenantScope::class);
    }

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class);
    }
}
