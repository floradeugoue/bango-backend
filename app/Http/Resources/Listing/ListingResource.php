<?php

namespace App\Http\Resources\Listing;

use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class ListingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        // Photos sous forme d'URLs simples et qualifications de pièces
        $photosList = $this->photos->map(fn (ListingMedia $m) => $m->url)->values()->all();
        $photoRooms = $this->photos->map(fn (ListingMedia $m) => $m->room?->value)->values()->all();

        // Vidéo principale de visite guidée
        $mainVideo = $this->videos->first();
        $tourDuration = null;
        if ($mainVideo && $mainVideo->duration) {
            $mins = floor($mainVideo->duration / 60);
            $secs = $mainVideo->duration % 60;
            $tourDuration = sprintf('%d:%02d', $mins, $secs);
        }

        // Métadonnées propriétaire
        $owner = $this->owner;
        $ownerData = null;
        if ($owner) {
            $ownerRole = ($owner->badge === 'agent') ? 'agent' : 'landlord';
            $ownerData = [
                'id' => $owner->id,
                'name' => $owner->name,
                'display_name' => $owner->display_name ?? $owner->name,
                'avatar_url' => $owner->avatar_url,
                'phone' => $owner->phone,
                'is_verified' => (bool) $owner->is_verified,
                'badge' => $owner->badge ?? 'none',
                'role' => $ownerRole,
                'meta' => $owner->is_verified ? 'Bailleur vérifié' : 'Bailleur non vérifié',
            ];
        }

        return [
            'id' => $this->id,
            'mode' => $this->mode?->value ?? (string) $this->mode,
            'kind' => $this->kind?->value ?? (string) $this->kind,
            'status' => $this->status?->value ?? (string) $this->status,
            'title' => $this->title,
            'price' => (float) $this->price,
            'currency' => $this->currency ?? 'XAF',
            'period' => $this->period,
            'negotiable' => (bool) $this->negotiable,
            'utilities_included' => (bool) $this->utilities_included,
            'bedrooms' => (int) $this->bedrooms,
            'showers' => (int) $this->showers,
            'area' => (float) $this->area,
            'amenities' => $this->amenities ?? [],
            'attributes' => $this->attributes ?? [],
            'location' => $this->location,
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'lat' => $this->lat !== null ? (float) $this->lat : null,
            'lng' => $this->lng !== null ? (float) $this->lng : null,
            'photos' => $photosList,
            'photo_rooms' => $photoRooms,
            'tour_url' => $mainVideo?->url,
            'tour_duration' => $tourDuration,
            'agent_visited' => (bool) $this->agent_visited,
            'charges' => (float) $this->charges,
            'published_at' => $this->published_at?->toISOString(),
            'owner' => $ownerData,
            'owner_phone' => $owner?->phone,
            'comments_count' => (int) ($this->comments_count ?? 0),
            'saves' => (int) $this->saves_count,
            'is_saved' => $viewer ? $this->isSavedBy($viewer) : false,
            'likes_count' => (int) ($this->likes_count ?? 0),
            'is_liked' => $viewer ? $this->isLikedBy($viewer) : false,
            'visit_fee' => (float) $this->visit_fee,
            'uses_agenda' => $this->usesAgenda(),
            'sponsored' => (bool) $this->sponsored,
            'media_count' => $this->photos->count() + $this->videos->count(),
        ];
    }
}
