<?php

namespace App\Http\Resources\Landlord;

use App\Models\Listing\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class LandlordPropertyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'mode' => $this->mode?->value ?? (string) $this->mode,
            'kind' => $this->kind?->value ?? (string) $this->kind,
            'status' => $this->status?->value ?? (string) $this->status,
            'price' => (float) $this->price,
            'currency' => $this->currency ?? 'XOF',
            'period' => $this->period,
            'charges' => (float) ($this->charges ?? 0),
            'visit_fee' => (float) ($this->visit_fee ?? 0),
            'deposit_months' => $this->deposit_months,
            'advance_months' => $this->advance_months,
            'bedrooms' => $this->bedrooms,
            'showers' => $this->showers,
            'area' => (float) ($this->area ?? 0),
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'location' => $this->location,
            'address' => $this->address,
            'views_count' => (int) ($this->views_count ?? 0),
            'saves_count' => (int) ($this->saves_count ?? 0),
            'pending_visits_count' => (int) ($this->pending_visits_count ?? 0),
            'confirmed_visits_count' => (int) ($this->confirmed_visits_count ?? 0),
            'photos' => $this->photos->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->url,
                'thumbnail_url' => $media->thumbnail_url ?? $media->url,
                'order' => $media->order,
                'room' => $media->room,
            ]),
            'videos' => $this->videos->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->url,
                'duration' => $media->duration,
            ]),
            'active_agent' => $this->activeDelegation ? [
                'id' => $this->activeDelegation->agent_id,
                'name' => $this->activeDelegation->agent->name ?? $this->activeDelegation->agent->display_name ?? null,
                'handle' => $this->activeDelegation->agent->handle ?? null,
                'capabilities' => $this->activeDelegation->capabilities ?? [],
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'taken_at' => $this->taken_at?->toIso8601String(),
        ];
    }
}
