<?php

namespace App\Http\Resources\Story;

use App\Models\Story\Story;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Story
 */
class StoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $isMine = $user ? $this->user_id === $user->id : false;
        $hasViewed = false;

        if ($user) {
            $hasViewed = $this->relationLoaded('views')
                ? $this->views->contains('viewer_id', $user->id)
                : $this->hasViewedBy($user);
        }

        return [
            'id' => (string) $this->id,
            'author' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'handle' => $this->user->handle,
                'display_name' => $this->user->display_name ?? $this->user->name,
                'avatar_url' => $this->user->avatar_url,
                'is_verified' => (bool) $this->user->is_verified,
            ],
            'audience' => $this->audience->value,
            'allow_replies' => (bool) $this->allow_replies,
            'allow_archive' => (bool) $this->allow_archive,
            'status' => $this->status->value,
            'is_mine' => $isMine,
            'has_viewed' => $hasViewed,
            'is_expired' => $this->isExpired(),
            'published_at' => $this->published_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'stats' => $isMine ? [
                'views_count' => (int) $this->views_count,
                'poll_votes_count' => (int) $this->poll_votes_count,
                'replies_count' => (int) $this->replies_count,
            ] : null,
            'slides' => StorySlideResource::collection($this->whenLoaded('slides')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
