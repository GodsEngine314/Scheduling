<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * PROJECTION of auth.v1.user.*. Ids are assigned by auth and arrive in the
 * event, so 'id' is mass-assignable here — the same reason stores.id is.
 *
 * Scheduling-owned rows point at users with nullOnDelete: losing an auth user
 * must not delete a shift, a punch or the record that a decision was taken.
 */
#[Fillable(['id', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    public function createdShifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'created_by_user_id');
    }

    public function approvedWorkSegments(): HasMany
    {
        return $this->hasMany(WorkSegment::class, 'approved_by_user_id');
    }

    public function correctedWorkSegments(): HasMany
    {
        return $this->hasMany(WorkSegment::class, 'times_corrected_by_user_id');
    }

    public function requestedEmployeeRequests(): HasMany
    {
        return $this->hasMany(EmployeeRequest::class, 'requested_by_user_id');
    }

    public function requestDecisions(): HasMany
    {
        return $this->hasMany(EmployeeRequestDecision::class);
    }
}
