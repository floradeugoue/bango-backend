<?php

namespace App\Http\Resources\Listing;

use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

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
        $viewer = $request->user() ?: Auth::guard('api')->user();
        $canSeeLandmark = $viewer && ($viewer->isAdmin()
            || $viewer->id === $this->owner_id
            || ($this->agent_id && $viewer->id === $this->agent_id));

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
                'name' => $owner->display_name ?: $owner->name,
                'display_name' => $owner->display_name ?: $owner->name,
                'handle' => $owner->handle,
                'avatar_url' => $owner->avatar_url,
                'phone' => $owner->phone,
                'is_verified' => (bool) $owner->is_verified,
                'certified' => (bool) ($owner->is_certified ?? false),
                'is_certified' => (bool) ($owner->is_certified ?? false),
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
            'floor' => $this->floor !== null ? (int) $this->floor : null,
            'available_from' => $this->available_from?->toDateString(),
            'min_lease_months' => $this->min_lease_months !== null ? (int) $this->min_lease_months : null,
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
            'saves_count' => (int) ($this->saves_count ?? 0),
            'is_saved' => $viewer ? $this->isSavedBy($viewer) : false,
            'shares' => (int) ($this->shares_count ?? 0),
            'shares_count' => (int) ($this->shares_count ?? 0),
            'likes_count' => (int) ($this->likes_count ?? 0),
            'is_liked' => $viewer ? $this->isLikedBy($viewer) : false,
            'visit_fee' => (float) $this->visit_fee,
            'uses_agenda' => $this->usesAgenda(),
            'landmark' => $canSeeLandmark ? $this->location : null,
            'auto_confirm' => (bool) ($this->auto_confirm ?? false),
            'sponsored' => (bool) $this->sponsored,
            'is_sponsored' => $this->isSponsored(),
            'campaign_id' => $this->campaignId(),
            'sponsored_by' => $this->sponsoredBy(),
            'media_count' => $this->photos->count() + $this->videos->count(),
        ];
    }
}
