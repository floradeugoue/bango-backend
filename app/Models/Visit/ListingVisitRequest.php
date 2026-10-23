<?php

namespace App\Models\Visit;

use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingVisitRequest extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'listing_visit_requests';

    protected $fillable = [
        'listing_id',
        'prospect_id',
        'owner_id',
        'agent_id',
        'proposals',
        'message',
        'status',
        'chosen_proposal_index',
        'chosen_slot_at',
        'visit_reservation_id',
        'viewed_by_landlord_at',
        'expires_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'proposals' => 'array',
            'chosen_proposal_index' => 'integer',
            'chosen_slot_at' => 'datetime',
            'viewed_by_landlord_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prospect_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(VisitReservation::class, 'visit_reservation_id');
    }
}
