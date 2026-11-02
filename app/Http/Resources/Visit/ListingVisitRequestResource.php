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
        $hasProspect = $this->relationLoaded('prospect') && $this->prospect;
        $hasListing = $this->relationLoaded('listing') && $this->listing;

        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'prospect_id' => $this->prospect_id,
            'owner_id' => $this->owner_id,
            'agent_id' => $this->agent_id,
            'status' => $this->status,
            'proposals' => $this->proposals ?? [],
            'message' => $this->message,
            'chosen_proposal_index' => $this->chosen_proposal_index,
            'chosen_slot_at' => $this->chosen_slot_at?->toISOString(),
            'visit_reservation_id' => $this->visit_reservation_id,
            'reservation_id' => $this->visit_reservation_id,
            'viewed_by_landlord_at' => $this->viewed_by_landlord_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'prospect' => $hasProspect ? [
                'id' => $this->prospect->id,
                'name' => $this->prospect->display_name ?? $this->prospect->name,
                'avatar_url' => $this->prospect->avatar_url ?? $this->prospect->avatar,
                'photo' => $this->prospect->avatar_url ?? $this->prospect->avatar,
                'phone' => $this->prospect->phone,
            ] : null,
            'listing' => $hasListing ? [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'photo' => $this->listing->cover_image_url ?? $this->listing->photos?->first()?->url,
                'photo_url' => $this->listing->cover_image_url ?? $this->listing->photos?->first()?->url,
                'price' => (float) $this->listing->price,
                'currency' => $this->listing->currency,
                'city' => $this->listing->city,
                'neighbourhood' => $this->listing->neighbourhood,
            ] : null,
        ];
    }
}
