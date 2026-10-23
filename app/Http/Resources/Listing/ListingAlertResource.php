<?php

namespace App\Http\Resources\Listing;

use App\Models\Listing\ListingAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingAlert
 */
class ListingAlertResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'mode' => $this->mode,
            'country_code' => $this->country_code,
            'city' => $this->city,
            'neighbourhoods' => $this->neighbourhoods ?? [],
            'kinds' => $this->kinds ?? [],
            'budget_min' => $this->budget_min !== null ? (float) $this->budget_min : null,
            'budget_max' => $this->budget_max !== null ? (float) $this->budget_max : null,
            'bedrooms' => $this->bedrooms ?? [],
            'showers' => $this->showers ?? [],
            'area_min' => $this->area_min !== null ? (float) $this->area_min : null,
            'area_max' => $this->area_max !== null ? (float) $this->area_max : null,
            'amenities' => $this->amenities ?? [],
            'verified_only' => (bool) $this->verified_only,
            'agent_visited_only' => (bool) $this->agent_visited_only,
            'utilities_included_only' => (bool) $this->utilities_included_only,
            'frequency' => $this->frequency ?? 'instant',
            'push' => (bool) $this->push,
            'email' => (bool) $this->email,
            'last_triggered_at' => $this->last_triggered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
