<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $attributes = [
        'account_status' => 'active',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'account_status' => AccountStatus::class,
            'suspended_at' => 'datetime',
        ];
    }

    public function organizerRequests(): HasMany
    {
        return $this->hasMany(OrganizerRequest::class);
    }

    public function reviewedOrganizerRequests(): HasMany
    {
        return $this->hasMany(OrganizerRequest::class, 'reviewed_by');
    }

    public function suspender(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suspended_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'organizer_id');
    }

    public function suspendedEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'suspended_by');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Reservation::class);
    }

    public function eventStaffAssignments(): HasMany
    {
        return $this->hasMany(EventStaffAssignment::class, 'staff_user_id');
    }

    public function assignedEvents(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_staff_assignments', 'staff_user_id', 'event_id')->withTimestamps();
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class, 'staff_user_id');
    }
}
