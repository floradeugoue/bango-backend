<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $badges = [];
        $badge = $this->badge ?? 'none';
        if ($badge === 'landlord') {
            $badges = ['Bailleur'];
        } elseif ($badge === 'agent') {
            $badges = ['Agent'];
        } elseif ($badge === 'double') {
            $badges = ['Bailleur', 'Agent'];
        } elseif ($badge === 'page') {
            $badges = ['Page'];
        }

        $tones = ['warm', 'sand', 'deep'];
        $hash = crc32((string) $this->id);
        $tone = $tones[abs($hash) % count($tones)];

        $isFollowing = $currentUser ? $currentUser->isFollowing($this->resource) : false;
        $isFollowedBy = $currentUser ? $this->isFollowing($currentUser) : false;
        $hasRequested = $currentUser ? $currentUser->hasRequestedToFollow($this->resource) : false;
        $isBlocked = $currentUser ? $currentUser->hasBlocked($this->resource) : false;
        $isBlockedBy = $currentUser ? $currentUser->isBlockedBy($this->resource) : false;
        $canViewContent = $currentUser ? $currentUser->canViewContentOf($this->resource) : ! $this->is_private;

        $place = collect([$this->neighbourhood, $this->city])->filter()->join(', ');

        return [
            'id' => $this->id,
            'name' => $this->display_name ?? $this->name,
            'handle' => $this->handle,
            'avatarUrl' => $this->avatar_url,
            'place' => $place,
            'countryCode' => $this->country_code,
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'bio' => $this->bio ?? '',
            'badge' => $badge,
            'badges' => $badges,
            'verified' => (bool) ($this->badge && $this->badge !== 'none' && ! $this->verification_lapsed),
            'verificationLapsed' => (bool) ($this->verification_lapsed ?? false),
            'private' => (bool) ($this->is_private ?? false),
            'tone' => $tone,
            'counts' => [
                'followers' => $this->followers()->count(),
                'following' => $this->following()->count(),
                'posts' => 0,
            ],
            'isFollowing' => $isFollowing,
            'isFollowedBy' => $isFollowedBy,
            'hasRequested' => $hasRequested,
            'isBlocked' => $isBlocked,
            'isBlockedBy' => $isBlockedBy,
            'canViewContent' => $canViewContent,
        ];
    }
}
