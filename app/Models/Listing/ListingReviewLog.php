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
 * @property int $operator_id
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $reason
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property-read Listing $listing
 * @property-read User $operator
 */
class ListingReviewLog extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'listing_review_logs';

    protected $fillable = [
        'id',
        'listing_id',
        'operator_id',
        'action',
        'from_status',
        'to_status',
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

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
