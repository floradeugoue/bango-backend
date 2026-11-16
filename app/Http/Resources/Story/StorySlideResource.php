<?php

namespace App\Http\Resources\Story;

use App\Models\Story\StoryLike;
use App\Models\Story\StorySlide;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StorySlide
 */
class StorySlideResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $isLiked = false;

        if ($user) {
            $isLiked = $this->relationLoaded('likes')
                ? $this->likes->contains('user_id', $user->id)
                : StoryLike::where('slide_id', $this->id)->where('user_id', $user->id)->exists();
        }

        $likesCount = $this->relationLoaded('likes')
            ? $this->likes->count()
            : StoryLike::where('slide_id', $this->id)->count();

        return [
            'id' => (string) $this->id,
            'story_id' => (string) $this->story_id,
            'media_type' => $this->media_type->value,
            'media_url' => $this->media_url,
            'thumbnail_url' => $this->thumbnail_url,
            'filter' => $this->filter,
            'caption' => $this->caption,
            'duration' => (int) ($this->duration ?? ($this->media_type->value === 'image' ? 5 : 15)),
            'order' => (int) $this->order,
            'sound' => $this->sound_metadata,
            'background' => $this->metadata['background'] ?? null,
            'drawings' => $this->metadata['drawings'] ?? null,
            'framing' => $this->metadata['framing'] ?? null,
            'stickers' => StoryStickerResource::collection($this->whenLoaded('stickers')),
            'is_liked' => $isLiked,
            'likes_count' => $likesCount,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
