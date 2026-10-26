<?php

namespace App\Models\Boost;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignResult extends Model
{
    use HasFactory;

    protected $table = 'campaign_results';

    protected $fillable = [
        'campaign_id',
        'user_id',
        'type',
        'impression_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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

    public function impression(): BelongsTo
    {
        return $this->belongsTo(CampaignImpression::class, 'impression_id');
    }
}
