<?php

namespace App\Models\Subscription;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'badge_type',
        'target_role',
        'features',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPrice::class, 'product_id');
    }

    public function activePrices(): HasMany
    {
        return $this->hasMany(SubscriptionPrice::class, 'product_id')
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', now());
            });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'product_id');
    }
}
