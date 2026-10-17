<?php

namespace App\Models\Listing;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $listing_id
 * @property string $dismissed_listing_id
 * @property int $operator_id
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read Listing $listing
 * @property-read Listing $dismissedListing
 * @property-read User $operator
 */
class ListingDuplicateDismissal extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'listing_duplicate_dismissals';

    protected $fillable = [
        'id',
        'listing_id',
        'dismissed_listing_id',
        'operator_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function dismissedListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'dismissed_listing_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
