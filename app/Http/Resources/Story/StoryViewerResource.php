<?php

namespace App\Http\Resources\Story;

use App\Models\Story\StoryLike;
use App\Models\Story\StoryPollVote;
use App\Models\Story\StoryReply;
use App\Models\Story\StoryView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryView
 */
class StoryViewerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->viewer;
        $page = $this->page_id ? ($this->relationLoaded('page') ? $this->page : $this->page()->first()) : null;
        $story = $this->story;
        $storyId = $this->story_id;
        $viewerId = $viewer->id;
        $pageId = $this->page_id;

        $pollVote = $story
            ? StoryPollVote::whereIn('story_slide_id', $story->slides()->pluck('id'))
                ->where('user_id', $viewerId)
                ->when($pageId, fn ($q) => $q->where('page_id', $pageId), fn ($q) => $q->whereNull('page_id'))
                ->first()
            : null;

        $reply = StoryReply::where('story_id', $storyId)
            ->where('user_id', $viewerId)
            ->when($pageId, fn ($q) => $q->where('page_id', $pageId), fn ($q) => $q->whereNull('page_id'))
            ->first();

        $like = StoryLike::where('story_id', $storyId)
            ->where('user_id', $viewerId)
            ->when($pageId, fn ($q) => $q->where('page_id', $pageId), fn ($q) => $q->whereNull('page_id'))
            ->first();

        if ($page) {
            $id = (int) $page->id;
            $name = $page->name;
            $handle = $page->handle;
            $displayName = $page->name;
            $avatarUrl = $page->logo_url;
            $isVerified = $page->isCertified();
        } else {
            $id = $viewer->id;
            $name = $viewer->display_name ?: $viewer->name;
            $handle = $viewer->handle;
            $displayName = $viewer->display_name ?: $viewer->name;
            $avatarUrl = $viewer->avatar_url;
            $isVerified = (bool) $viewer->is_verified;
        }

        return [
            'id' => $id,
            'name' => $name,
            'handle' => $handle,
            'display_name' => $displayName,
            'avatar_url' => $avatarUrl,
            'is_verified' => $isVerified,
            'page_id' => $pageId ? (int) $pageId : null,
            'page' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'avatar_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
            ] : null,
            'viewed_at' => $this->viewed_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'has_voted' => $pollVote !== null,
            'vote' => $pollVote ? [
                'slide_id' => (string) ($pollVote->story_slide_id ?? $pollVote->slide_id),
                'sticker_id' => (string) $pollVote->sticker_id,
                'option_index' => (int) $pollVote->option_index,
            ] : null,
            'has_replied' => $reply !== null,
            'reply' => $reply ? [
                'id' => (string) $reply->id,
                'message' => $reply->text ?? $reply->message,
                'type' => $reply->type instanceof \BackedEnum ? $reply->type->value : (string) $reply->type,
                'created_at' => $reply->created_at?->toIso8601String(),
            ] : null,
            'has_liked' => $like !== null,
            'liked' => $like !== null,
        ];
    }
}
