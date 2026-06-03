<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBackgroundLog extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
