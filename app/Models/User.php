<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\Gender;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'handle',
        'display_name',
        'avatar_url',
        'bio',
        'birth_date',
        'gender',
        'gender_hidden',
        'phone',
        'phone_verified_at',
        'country_code',
        'country_id',
        'city',
        'city_id',
        'neighbourhood',
        'neighbourhood_id',
        'locale',
        'account_type',
        'is_verified',
        'status',
        'locked_until',
        'suspension_reason',
        'suspended_from',
        'suspended_until',
        'onboarding_progress',
        'two_factor_method',
        'two_factor_secret',
        'backup_codes',
        'pending_email',
        'pending_email_code',
        'pending_email_expires_at',
        'pending_email_attempts',
        'scheduled_for_deletion_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'backup_codes',
        'pending_email_code',
        'pending_email_attempts',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'gender_hidden' => 'boolean',
            'is_verified' => 'boolean',
            'locked_until' => 'datetime',
            'suspended_from' => 'datetime',
            'suspended_until' => 'datetime',
            'onboarding_progress' => 'array',
            'backup_codes' => 'array',
            'gender' => Gender::class,
            'account_type' => AccountType::class,
            'scheduled_for_deletion_at' => 'datetime',
        ];
    }

    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function isAdmin(): bool
    {
        return $this->role?->slug === 'admin';
    }

    public function hasRole(string $roleSlug): bool
    {
        return $this->role?->slug === $roleSlug;
    }

    public function housingSearch(): HasOne
    {
        return $this->hasOne(HousingSearch::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function neighbourhood()
    {
        return $this->belongsTo(Neighbourhood::class);
    }
}
