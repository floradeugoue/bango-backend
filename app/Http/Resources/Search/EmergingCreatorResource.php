<?php

namespace App\Http\Resources\Search;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmergingCreatorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $currentUser */
        $currentUser = $request->user();

        $badge = $this->badge ?? 'none';
        $isLapsed = (bool) ($this->verification_lapsed ?? false);
        $isActiveBadge = ($badge !== 'none' && ! $isLapsed);

        $badges = match ($badge) {
            'double' => ['Bailleur', 'Agent'],
            'landlord' => ['Bailleur'],
            'agent' => ['Agent'],
            default => [],
        };

        $place = collect([$this->neighbourhood, $this->city])->filter()->join(', ');

        return [
            'id' => (int) $this->id,
            'name' => (string) ($this->display_name ?? $this->name ?? ''),
            'handle' => (string) ($this->handle ?? ''),
            'avatar_url' => $this->avatar_url,
            'avatarUrl' => $this->avatar_url,
            'badge' => $isActiveBadge ? $badge : 'none',
            'badges' => $badges,
            'is_verified' => (bool) ($this->is_verified && ! $isLapsed),
            'verified' => $isActiveBadge,
            'account_type' => $this->account_type,
            'place' => $place,
            'bio' => (string) ($this->bio ?? ''),
            'followers_count' => (int) ($this->followers_count ?? 0),
            'posts_count' => (int) ($this->posts_count ?? 0),
            'reels_count' => (int) ($this->reels_count ?? 0),
            'is_following' => $currentUser ? $currentUser->isFollowing($this->resource) : false,
            'isFollowing' => $currentUser ? $currentUser->isFollowing($this->resource) : false,
        ];
    }
}
