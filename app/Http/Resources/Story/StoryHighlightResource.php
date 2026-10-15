<?php

namespace App\Http\Resources\Story;

use App\Models\Story\StoryHighlight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryHighlight
 */
class StoryHighlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $coverUrl = $this->cover_media_url;
        if (! $coverUrl && $this->coverStory) {
            $coverUrl = $this->coverStory->slides->first()?->thumbnail_url ?? $this->coverStory->slides->first()?->media_url;
        }

        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'cover_story_id' => $this->cover_story_id ? (string) $this->cover_story_id : null,
            'cover_media_url' => $coverUrl,
            'order' => (int) $this->order,
            'stories_count' => $this->relationLoaded('stories') ? $this->stories->count() : null,
            'stories' => StoryResource::collection($this->whenLoaded('stories')),
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'handle' => $this->user->handle,
                'display_name' => $this->user->display_name ?? $this->user->name,
                'avatar_url' => $this->user->avatar_url,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
