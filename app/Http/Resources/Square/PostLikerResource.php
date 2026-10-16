<?php

namespace App\Http\Resources\Square;

use App\Models\User;
use App\Services\Subscription\BadgeResolutionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostLikerResource extends JsonResource
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

        $ringColor = match ($badge) {
            'double', 'landlord' => BadgeResolutionService::COLOR_DOUBLE,
            'agent' => BadgeResolutionService::COLOR_AGENT,
            default => null,
        };

        $ringStyle = match ($badge) {
            'double' => 'double',
            'landlord', 'agent' => 'single',
            default => 'none',
        };

        if ($isLapsed) {
            $badges = [];
            $ringColor = null;
            $ringStyle = 'none';
        }

        $place = collect([$this->neighbourhood, $this->city])->filter()->join(', ');

        return [
            'id' => (int) $this->id,
            'name' => (string) ($this->display_name ?? $this->name ?? ''),
            'handle' => (string) ($this->handle ?? ''),
            'avatar_url' => $this->avatar_url,
            'avatarUrl' => $this->avatar_url,
            'badge' => $isActiveBadge ? $badge : 'none',
            'badges' => $badges,
            'ring_color' => $ringColor,
            'ring_style' => $ringStyle,
            'is_verified' => (bool) ($this->is_verified && ! $isLapsed),
            'verified' => $isActiveBadge,
            'account_type' => $this->account_type,
            'place' => $place,
            'bio' => (string) ($this->bio ?? ''),
            'liked_at' => $this->liked_at ? Carbon::parse($this->liked_at)->toIso8601String() : null,
            'is_following' => $currentUser ? $currentUser->isFollowing($this->resource) : false,
            'isFollowing' => $currentUser ? $currentUser->isFollowing($this->resource) : false,
        ];
    }
}
