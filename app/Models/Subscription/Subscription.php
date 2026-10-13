<?php

namespace App\Models\Subscription;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'price_id',
        'badge_type',
        'cadence',
        'state',
        'amount',
        'currency',
        'current_period_start',
        'current_period_end',
        'grace_until',
        'failed_attempts',
        'auto_renew',
        'next_renewal',
        'canceled_at',
        'cancel_reason',
        'cancel_feedback',
        'payment_reference',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_until' => 'datetime',
            'failed_attempts' => 'integer',
            'auto_renew' => 'boolean',
            'next_renewal' => 'datetime',
            'canceled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPrice::class, 'price_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class, 'subscription_id')->orderByDesc('created_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', 'active')
            ->where('current_period_end', '>', now());
    }

    public function scopeInGrace(Builder $query): Builder
    {
        return $query->where('state', 'grace')
            ->where('grace_until', '>', now());
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $sub) {
                $sub->where('state', 'active')
                    ->where('current_period_end', '>', now());
            })->orWhere(function (Builder $sub) {
                $sub->where('state', 'grace')
                    ->where('grace_until', '>', now());
            });
        });
    }

    public function scopeForBadge(Builder $query, string $badge): Builder
    {
        return $query->where('badge_type', $badge);
    }

    public function isActive(): bool
    {
        return $this->state === 'active' && $this->current_period_end && $this->current_period_end->isFuture();
    }

    public function isInGrace(): bool
    {
        return $this->state === 'grace' && $this->grace_until && $this->grace_until->isFuture();
    }

    public function isUsable(): bool
    {
        return $this->isActive() || $this->isInGrace();
    }

    public function isExpired(): bool
    {
        if ($this->state === 'expired') {
            return true;
        }

        if ($this->isInGrace()) {
            return false;
        }

        return $this->current_period_end && $this->current_period_end->isPast();
    }
}
