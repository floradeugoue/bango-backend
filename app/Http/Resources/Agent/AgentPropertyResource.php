<?php

namespace App\Http\Resources\Agent;

use App\Models\Listing\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class AgentPropertyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $delegation = $this->delegations->first();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'mode' => $this->mode?->value ?? (string) $this->mode,
            'kind' => $this->kind?->value ?? (string) $this->kind,
            'status' => $this->status?->value ?? (string) $this->status,
            'price' => (float) $this->price,
            'currency' => $this->currency ?? 'XOF',
            'charges' => (float) ($this->charges ?? 0),
            'visit_fee' => (float) ($this->visit_fee ?? 0),
            'bedrooms' => $this->bedrooms,
            'showers' => $this->showers,
            'area' => (float) ($this->area ?? 0),
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'location' => $this->location,
            'capabilities' => $delegation?->capabilities ?? [],
            'effective_capabilities' => $delegation?->capabilities ?? [],
            'owner' => [
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? $this->owner->display_name ?? null,
                'handle' => $this->owner->handle ?? null,
                'avatar_url' => $this->owner->avatar_url ?? null,
            ],
            'landlord' => [
                'id' => $this->owner_id,
                'name' => $this->owner->name ?? $this->owner->display_name ?? null,
                'handle' => $this->owner->handle ?? null,
                'avatar_url' => $this->owner->avatar_url ?? null,
            ],
            'photos' => $this->photos->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->url,
                'order' => $media->order,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
