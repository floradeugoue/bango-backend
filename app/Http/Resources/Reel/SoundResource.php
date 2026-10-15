<?php

namespace App\Http\Resources\Reel;

use App\Models\Reel\Sound;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sound
 */
class SoundResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isFavourite = false;

        if ($user) {
            $isFavourite = $this->favouritedBy()->where('users.id', $user->id)->exists();
        }

        $totalUses = $this->base_uses + $this->uses_count;
        $usesLabel = $totalUses >= 1000
            ? round($totalUses / 1000, 1).' k vidéos'
            : "{$totalUses} vidéos";

        return [
            'id' => $this->id,
            'title' => $this->title,
            'artist' => $this->artist,
            'genre' => $this->genre,
            'audio_url' => $this->audio_url ? app(MediaStorageService::class)->getUrl($this->audio_url) : null,
            'duration' => $this->duration_seconds,
            'duration_seconds' => $this->duration_seconds,
            'uses' => $usesLabel,
            'uses_count' => $totalUses,
            'original' => (bool) $this->is_original,
            'original_reel_id' => $this->original_reel_id,
            'available' => ! $this->is_removed && (bool) $this->is_published && $this->rights_status === 'verified',
            'locked' => (bool) $this->is_locked,
            'territories' => $this->territories,
            'provenance' => $this->provenance,
            'license_type' => $this->license_type,
            'license_name' => $this->license_name,
            'license_url' => $this->license_url,
            'rights_status' => $this->rights_status,
            'is_published' => (bool) $this->is_published,
            'is_favourite' => $isFavourite,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
