<?php

namespace App\Http\Resources\Visit;

use App\Models\Visit\ListingVisitRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingVisitRequest
 */
class ListingVisitRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'prospect_id' => $this->prospect_id,
            'owner_id' => $this->owner_id,
            'status' => $this->status,
            'proposals' => $this->proposals ?? [],
            'message' => $this->message,
            'chosen_proposal_index' => $this->chosen_proposal_index,
            'chosen_slot_at' => $this->chosen_slot_at?->toISOString(),
            'visit_reservation_id' => $this->visit_reservation_id,
            'viewed_by_landlord_at' => $this->viewed_by_landlord_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
