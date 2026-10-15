<?php

namespace App\Http\Resources\Listing;

use App\Models\Listing\Listing;
use App\Services\Listing\ListingPricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class ListingDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isPrivileged = $viewer && ($viewer->isAdmin() || $viewer->id === $this->owner_id || ($this->agent_id && $viewer->id === $this->agent_id));

        // Calcul de la ventilation des frais d'entrée (CR-45)
        $pricingService = app(ListingPricingService::class);
        $pricing = $pricingService->calculatePricing([
            'mode' => $this->mode,
            'price' => (float) $this->price,
            'deposit_months' => (int) $this->deposit_months,
            'advance_months' => (int) $this->advance_months,
            'visit_fee' => (float) $this->visit_fee,
            'charges' => (float) $this->charges,
        ]);

        // Base data from ListingResource
        $base = (new ListingResource($this->resource))->toArray($request);

        return array_merge($base, [
            'description' => $this->description,
            'amenities' => $this->amenities ?? [],
            'features' => $this->features ?? [],
            'deposit_months' => (int) $this->deposit_months,
            'advance_months' => (int) $this->advance_months,
            'move_in_costs' => $pricing['costs'],
            'upfront_total' => $pricing['upfront'],
            'monthly_total' => $pricing['monthly'],
            // Données privées / modération visibles uniquement par les personnes autorisées
            'address' => $isPrivileged ? $this->address : null,
            'submission_key' => $isPrivileged ? $this->submission_key : null,
            'draft_step' => $isPrivileged ? $this->draft_step : null,
            'review_due_at' => $isPrivileged ? $this->review_due_at?->toISOString() : null,
            'rejection_reason' => $isPrivileged ? $this->rejection_reason : null,
            'all_media' => ListingMediaResource::collection($this->media),
        ]);
    }
}
