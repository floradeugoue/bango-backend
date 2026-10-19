<?php

namespace App\Models\Subscription;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingChangeProposal extends Model
{
    use HasFactory;

    protected $table = 'pricing_change_proposals';

    protected $fillable = [
        'price_id',
        'subscription_price_id',
        'proposed_by_id',
        'approved_by_id',
        'new_price_amount',
        'notice_days',
        'effective_date',
        'status',
        'reason',
        'simulation_impact',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'new_price_amount' => 'decimal:2',
            'notice_days' => 'integer',
            'effective_date' => 'datetime',
            'simulation_impact' => 'array',
        ];
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPrice::class, 'price_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
