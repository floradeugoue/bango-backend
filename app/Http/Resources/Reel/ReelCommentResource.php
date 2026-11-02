<?php

namespace App\Http\Resources\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Page\Page;
use App\Models\Reel\ReelComment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

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
        $user = $request->user() ?: Auth::guard('api')->user();
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
            'answer_to_id' => $this->parent_id ? (int) $this->parent_id : null,
            'body' => $this->body,
            'language' => (string) ($this->language ?? 'fr'),
            'likes_count' => (int) $this->likes_count,
            'dislikes_count' => (int) $this->dislikes_count,
            'replies_count' => (int) $this->replies_count,
            'is_edited' => (bool) $this->is_edited,
            'user_reaction' => $userReaction,
            'liked' => $userReaction === ReelReactionType::Like->value,
            'disliked' => $userReaction === ReelReactionType::Dislike->value,
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'author' => ($page = $this->page_id ? ($this->relationLoaded('page') ? $this->page : Page::find($this->page_id)) : null) ? [
                'id' => (string) $page->id,
                'name' => $page->name,
                'displayName' => $page->name,
                'display_name' => $page->name,
                'handle' => '@'.$page->handle,
                'avatar_url' => $page->logo_url,
                'verified' => $page->isCertified(),
                'certified' => $page->isCertified(),
                'is_certified' => $page->isCertified(),
                'badge' => $page->isCertified() ? 'page_certified' : 'none',
            ] : [
                'id' => $author?->id,
                'name' => $author?->display_name ?: ($author?->name ?? 'Anonyme'),
                'displayName' => $author?->display_name ?: ($author?->name ?? 'Anonyme'),
                'display_name' => $author?->display_name ?: ($author?->name ?? 'Anonyme'),
                'handle' => '@'.($author?->handle ?? 'user'),
                'avatar_url' => $author?->avatar_url,
                'verified' => (bool) ($author?->is_verified ?? false),
                'certified' => (bool) ($author?->is_certified ?? false),
                'is_certified' => (bool) ($author?->is_certified ?? false),
                'badge' => $author?->badge,
            ],
            'replies' => ReelCommentResource::collection($this->whenLoaded('replies')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
