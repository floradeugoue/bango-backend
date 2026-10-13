<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'cadence',
        'price_amount',
        'currency',
        'duration_days',
        'savings_description',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SubscriptionProduct::class, 'product_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'price_id');
    }

    public function isEffective(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();
        if ($this->effective_from && $this->effective_from->isFuture()) {
            return false;
        }

        if ($this->effective_until && $this->effective_until->isPast()) {
            return false;
        }

        return true;
    }
}
