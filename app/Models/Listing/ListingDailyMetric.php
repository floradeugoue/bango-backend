<?php

namespace App\Models\Listing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingDailyMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'metric_date',
        'views',
        'video_views',
        'favorites',
        'contacts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'views' => 'integer',
            'video_views' => 'integer',
            'favorites' => 'integer',
            'contacts' => 'integer',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
