<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\Gender;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\Housing\HousingSearch;
use App\Models\Payment\PaymentTransaction;
use App\Models\Profile\FollowRequest;
use App\Models\Profile\Interest;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostCommentReaction;
use App\Models\Square\PostLike;
use App\Models\Square\PostShare;
use App\Models\Square\PostTag;
use App\Models\Subscription\BadgeHistory;
use App\Models\Subscription\Subscription;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'is_private',
        'privacy_settings',
        'badge',
        'verification_lapsed',
        'restricted_until',
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
            'is_private' => 'boolean',
            'privacy_settings' => 'array',
            'verification_lapsed' => 'boolean',
            'restricted_until' => 'datetime',
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

    /**
     * Users who follow this user.
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')
            ->withTimestamps();
    }

    /**
     * Users whom this user follows.
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')
            ->withTimestamps();
    }

    /**
     * Follow requests received by this user.
     */
    public function receivedFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'to_id');
    }

    /**
     * Follow requests sent by this user.
     */
    public function sentFollowRequests(): HasMany
    {
        return $this->hasMany(FollowRequest::class, 'from_id');
    }

    /**
     * Users blocked by this user.
     */
    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocker_id', 'blocked_id')
            ->withTimestamps();
    }

    /**
     * Users who have blocked this user.
     */
    public function blockedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocked_id', 'blocker_id')
            ->withTimestamps();
    }

    /**
     * Check if this user is following another user.
     */
    public function isFollowing(int|string|User $user): bool
    {
        $id = $user instanceof User ? $user->id : (int) $user;

        return $this->following()->where('following_id', $id)->exists();
    }

    /**
     * Check if this user sent a pending follow request to another user.
     */
    public function hasRequestedToFollow(int|string|User $user): bool
    {
        $id = $user instanceof User ? $user->id : (int) $user;

        return $this->sentFollowRequests()->where('to_id', $id)->where('status', 'pending')->exists();
    }

    /**
     * Check if this user has blocked another user.
     */
    public function hasBlocked(int|string|User $user): bool
    {
        $id = $user instanceof User ? $user->id : (int) $user;

        return $this->blockedUsers()->where('blocked_id', $id)->exists();
    }

    /**
     * Check if this user is blocked by another user.
     */
    public function isBlockedBy(int|string|User $user): bool
    {
        $id = $user instanceof User ? $user->id : (int) $user;

        return $this->blockedByUsers()->where('blocker_id', $id)->exists();
    }

    /**
     * Likes effectués par cet utilisateur sur les publications Square.
     */
    public function postLikes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    /**
     * Commentaires rédigés par cet utilisateur sur Square.
     */
    public function postComments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /**
     * Réactions (likes / dislikes) sur les commentaires de Square.
     */
    public function postCommentReactions(): HasMany
    {
        return $this->hasMany(PostCommentReaction::class);
    }

    /**
     * Partages effectués par cet utilisateur sur les publications Square.
     */
    public function postShares(): HasMany
    {
        return $this->hasMany(PostShare::class);
    }

    /**
     * Check if this user can view the full content of another user.
     */
    public function canViewContentOf(User $target): bool
    {
        if ($this->id === $target->id) {
            return true;
        }

        if ($this->hasBlocked($target) || $this->isBlockedBy($target)) {
            return false;
        }

        if (! $target->is_private) {
            return true;
        }

        return $this->isFollowing($target);
    }

    /**
     * All subscriptions of this user.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->orderByDesc('created_at');
    }

    /**
     * Active or usable subscriptions (including grace period).
     */
    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('state', 'active')
                        ->where('current_period_end', '>', now());
                })->orWhere(function ($q) {
                    $q->where('state', 'grace')
                        ->where('grace_until', '>', now());
                });
            });
    }

    /**
     * Badge history entries for this user.
     */
    public function badgeHistories(): HasMany
    {
        return $this->hasMany(BadgeHistory::class)->orderByDesc('created_at');
    }

    /**
     * Check if user has an active or usable subscription for a badge.
     */
    public function hasActiveSubscription(string $badge): bool
    {
        return $this->activeSubscriptions()->where('badge_type', $badge)->exists();
    }

    /**
     * Financial transactions belonging to this user.
     */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderByDesc('created_at');
    }

    /**
     * Publications Square créées par cet utilisateur.
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class)->orderByDesc('created_at');
    }

    /**
     * Identifications de cet utilisateur sur des médias photos Square.
     */
    public function taggedInMedia(): HasMany
    {
        return $this->hasMany(PostTag::class);
    }
}
