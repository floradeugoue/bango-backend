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
 * @property int $agent_id
 * @property Carbon $certified_at
 * @property string|null $notes
 * @property array|null $evidence
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Listing $listing
 * @property-read User $agent
 */
class ListingAgentCertification extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'listing_agent_certifications';

    protected $fillable = [
        'id',
        'listing_id',
        'agent_id',
        'certified_at',
        'notes',
        'evidence',
    ];

    protected function casts(): array
    {
        return [
            'certified_at' => 'datetime',
            'evidence' => 'array',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
