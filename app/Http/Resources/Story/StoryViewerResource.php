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
        $storyId = $this->story_id;
        $viewerId = $viewer->id;

        $pollVote = StoryPollVote::where('story_id', $storyId)
            ->where('user_id', $viewerId)
            ->first();

        $reply = StoryReply::where('story_id', $storyId)
            ->where('user_id', $viewerId)
            ->first();

        $like = StoryLike::where('story_id', $storyId)
            ->where('user_id', $viewerId)
            ->first();

        return [
            'id' => $viewer->id,
            'name' => $viewer->name,
            'handle' => $viewer->handle,
            'display_name' => $viewer->display_name ?? $viewer->name,
            'avatar_url' => $viewer->avatar_url,
            'is_verified' => (bool) $viewer->is_verified,
            'viewed_at' => $this->viewed_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
            'has_voted' => $pollVote !== null,
            'vote' => $pollVote ? [
                'slide_id' => (string) $pollVote->slide_id,
                'sticker_id' => (string) $pollVote->sticker_id,
                'option_index' => (int) $pollVote->option_index,
            ] : null,
            'has_replied' => $reply !== null,
            'reply' => $reply ? [
                'id' => (string) $reply->id,
                'message' => $reply->message,
                'type' => $reply->type,
                'created_at' => $reply->created_at?->toIso8601String(),
            ] : null,
            'has_liked' => $like !== null,
            'liked' => $like !== null,
        ];
    }
}
