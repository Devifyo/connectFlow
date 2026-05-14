<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];
    protected $primaryKey = 'tenant_id';

    protected $casts = [
        'agency_profile' => 'array',
        'face_recognition_enabled' => 'boolean',
    ];
}
