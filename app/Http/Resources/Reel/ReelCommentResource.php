<?php

namespace App\Http\Resources\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\ReelComment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReelComment
 */
class ReelCommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $author = $this->user;

        $userReaction = null;
        if ($user) {
            if ($this->relationLoaded('reactions')) {
                $reaction = $this->reactions->firstWhere('user_id', $user->id);
                $userReaction = $reaction?->type instanceof ReelReactionType
                    ? $reaction->type->value
                    : ($reaction?->type ?? null);
            } else {
                $reaction = $this->reactions()->where('user_id', $user->id)->first();
                $userReaction = $reaction?->type instanceof ReelReactionType
                    ? $reaction->type->value
                    : ($reaction?->type ?? null);
            }
        }

        return [
            'id' => (string) $this->id,
            'reel_id' => (string) $this->reel_id,
            'parent_id' => $this->parent_id ? (string) $this->parent_id : null,
            'body' => $this->body,
            'likes_count' => (int) $this->likes_count,
            'dislikes_count' => (int) $this->dislikes_count,
            'replies_count' => (int) $this->replies_count,
            'is_edited' => (bool) $this->is_edited,
            'user_reaction' => $userReaction,
            'liked' => $userReaction === ReelReactionType::Like->value,
            'disliked' => $userReaction === ReelReactionType::Dislike->value,
            'author' => [
                'id' => $author?->id,
                'name' => $author?->name ?? 'Anonyme',
                'handle' => '@'.($author?->handle ?? 'user'),
                'avatar_url' => $author?->avatar_url,
                'verified' => (bool) ($author?->is_verified ?? false),
                'badge' => $author?->badge,
            ],
            'replies' => ReelCommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
