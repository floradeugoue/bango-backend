<?php

namespace App\Http\Resources;

use App\Models\Profile\Follow;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User|null $currentUser */
        $currentUser = $request->user();

        $place = collect([$this->neighbourhood, $this->city])->filter()->join(', ');

        $activePage = $request->attributes->get('active_page');
        $isFollowing = false;
        $hasRequested = false;

        if ($activePage) {
            $isFollowing = Follow::where('follower_page_id', $activePage->id)
                ->where('following_id', $this->id)
                ->exists();
        } elseif ($currentUser) {
            $isFollowing = $currentUser->isFollowing($this->resource);
            $hasRequested = $currentUser->hasRequestedToFollow($this->resource);
        }

        return [
            'id' => $this->id,
            'name' => $this->display_name ?: $this->name,
            'handle' => $this->handle,
            'avatarUrl' => $this->avatar_url,
            'badge' => $this->badge ?? 'none',
            'verified' => (bool) ($this->badge && $this->badge !== 'none' && ! $this->verification_lapsed),
            'certified' => (bool) ($this->is_certified ?? false),
            'is_certified' => (bool) ($this->is_certified ?? false),
            'place' => $place,
            'bio' => $this->bio ?? '',
            'isFollowing' => $isFollowing,
            'hasRequested' => $hasRequested,
        ];
    }
}
