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
 * @property string $primary_listing_id
 * @property string $secondary_listing_id
 * @property int $operator_id
 * @property string|null $reason
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property-read Listing $primaryListing
 * @property-read Listing $secondaryListing
 * @property-read User $operator
 */
class ListingMerge extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'listing_merges';

    protected $fillable = [
        'id',
        'primary_listing_id',
        'secondary_listing_id',
        'operator_id',
        'reason',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function primaryListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'primary_listing_id');
    }

    public function secondaryListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'secondary_listing_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
