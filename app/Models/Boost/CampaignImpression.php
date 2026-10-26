<?php

namespace App\Models\Boost;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignImpression extends Model
{
    use HasFactory;

    protected $table = 'campaign_impressions';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'shown_at',
    ];

    protected function casts(): array
    {
        return [
            'shown_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(CampaignResult::class, 'impression_id');
    }
}
