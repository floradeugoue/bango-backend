<?php

namespace App\Http\Resources\Story;

use App\Models\Page\Page;
use App\Models\Story\Story;
use App\Models\Story\StoryLike;
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
        $isLiked = false;
        if ($user) {
            $hasViewed = $this->relationLoaded('views')
                ? $this->views->contains('viewer_id', $user->id)
                : $this->hasViewedBy($user);

            $isLiked = $this->relationLoaded('likes')
                ? $this->likes->contains('user_id', $user->id)
                : StoryLike::where('story_id', $this->id)->where('user_id', $user->id)->exists();
        }

        $page = $this->page_id ? ($this->relationLoaded('page') ? $this->page : Page::find($this->page_id)) : null;

        $authorId = $page ? (int) $page->id : (int) $this->user->id;
        $authorName = $page ? $page->name : ($this->user->display_name ?: $this->user->name);
        $authorHandle = $page ? $page->handle : $this->user->handle;
        $authorAvatar = $page ? $page->logo_url : $this->user->avatar_url;
        $authorVerified = $page ? $page->isCertified() : (bool) $this->user->is_verified;
        $authorBadge = $page ? ($page->isCertified() ? 'page_certified' : 'none') : ($this->user?->badge ?? 'none');
        $userBadge = $this->user?->badge ?? 'none';

        return [
            'id' => (string) $this->id,
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'page' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
                'badge' => 'page_certified',
            ] : null,
            'author' => [
                'id' => $authorId,
                'name' => $authorName,
                'handle' => $authorHandle,
                'display_name' => $authorName,
                'avatar_url' => $authorAvatar,
                'is_verified' => $authorVerified,
                'certified' => $authorVerified,
                'is_certified' => $authorVerified,
                'badge' => $authorBadge,
            ],
            'user' => $this->user ? [
                'id' => (int) $this->user->id,
                'name' => $this->user->name,
                'handle' => $this->user->handle,
                'display_name' => $this->user->display_name ?: $this->user->name,
                'avatar_url' => $this->user->avatar_url,
                'is_verified' => (bool) $this->user->is_verified,
                'badge' => $userBadge,
            ] : null,
            'author_badge' => $authorBadge,
            'audience' => $this->audience->value,
            'allow_replies' => (bool) $this->allow_replies,
            'allow_archive' => (bool) $this->allow_archive,
            'status' => $this->status->value,
            'is_mine' => $isMine,
            'has_viewed' => $hasViewed,
            'is_liked' => $isLiked,
            'likes_count' => (int) $this->likes_count,
            'is_expired' => $this->isExpired(),
            'is_active' => $this->isActive(),
            'published_at' => $this->published_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'stats' => $isMine ? [
                'views_count' => (int) $this->views_count,
                'poll_votes_count' => (int) $this->poll_votes_count,
                'replies_count' => (int) $this->replies_count,
                'likes_count' => (int) $this->likes_count,
            ] : null,
            'slides' => StorySlideResource::collection($this->whenLoaded('slides')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
