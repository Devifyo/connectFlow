<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    protected $guarded = [];

    protected $appends = ['url'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function getUrlAttribute(): string
    {
        return url("/api/messages/attachments/{$this->id}");
    }
}
