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
        'maintenance_enabled' => 'boolean',
        'maintenance_started_at' => 'datetime',
    ];

    /** Payload shown to members while the site is down, or null when it is up. */
    public function maintenanceState(): ?array
    {
        if (!$this->maintenance_enabled) return null;
        return [
            'reason' => $this->maintenance_reason,
            'since' => $this->maintenance_started_at?->toIso8601String(),
            'company' => $this->company_name,
        ];
    }
}
