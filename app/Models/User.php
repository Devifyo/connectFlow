<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'tenant_id', 'is_active', 'designation', 'employee_id', 'joining_date', 'salary', 'min_hours_per_day', 'address', 'profile_picture', 'face_enrolled_at', 'higher_education', 'date_of_birth', 'phone_country_code', 'phone_number', 'notification_email'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $appends = ['profile_picture_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'date_of_birth' => 'date',
            'face_enrolled_at' => 'datetime',
            'face_exempt' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\TenantScope);
    }

    public function profileCompletion(): array
    {
        $fields = [
            'name' => !empty($this->name),
            'email' => !empty($this->email),
            'profile_picture' => !empty($this->profile_picture),
            'address' => !empty($this->address),
            'higher_education' => !empty($this->higher_education),
            'date_of_birth' => !empty($this->date_of_birth),
            'phone_number' => !empty($this->phone_number),
        ];

        $completed = count(array_filter($fields));
        $total = count($fields);

        return [
            'fields' => $fields,
            'completed' => $completed,
            'total' => $total,
            'percentage' => round(($completed / $total) * 100),
        ];
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        if (!$this->profile_picture) return null;
        return \App\Support\IdHash::signedUrl($this->id);
    }

    public function positions()
    {
        return $this->belongsToMany(Position::class, 'position_user')->withTimestamps()->orderBy('sort_order');
    }

    public function bids()
    {
        return $this->hasMany(Bid::class);
    }

    public function timeLogs()
    {
        return $this->hasMany(TimeLog::class);
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot('last_read_at', 'role', 'can_send')
            ->withTimestamps();
    }

    /**
     * Tenant admins can always create group chats; others need a position
     * the admin has flagged with "can create groups" (CEO / CTO by default).
     */
    public function canCreateGroups(): bool
    {
        if (!$this->tenant_id) return false;
        if ($this->hasRole('TenantAdmin')) return true;

        return $this->positions()->where('is_active', true)->where('can_create_groups', true)->exists();
    }

    public function globalMessages()
    {
        return $this->belongsToMany(GlobalMessage::class, 'global_message_recipients')
            ->withPivot('read_at', 'dismissed_at')
            ->withTimestamps();
    }

    /**
     * Address for notification emails, or null when it's on a suppressed
     * (undeliverable) domain — sending there just bounces back to us.
     */
    public function getNotificationEmailAddress(): ?string
    {
        $address = $this->notification_email ?: $this->email;
        $domain = strtolower(substr(strrchr((string) $address, '@') ?: '', 1));

        if ($domain === '' || in_array($domain, config('mail.suppressed_domains', []), true)) {
            return null;
        }

        return $address;
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
