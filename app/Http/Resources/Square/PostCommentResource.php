<?php

namespace App\Http\Resources\Square;

use App\Models\Square\PostComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin PostComment
 */
class PostCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUser = $request->user();
        $author = $this->user;
        $myReaction = $currentUser ? $this->reactionOf($currentUser) : null;
        $isMine = $currentUser ? (int) $this->user_id === (int) $currentUser->id : false;

        return [
            'id' => $this->id,
            'postId' => (int) $this->post_id,
            'post_id' => (int) $this->post_id,
            'parentId' => $this->parent_id ? (int) $this->parent_id : null,
            'parent_id' => $this->parent_id ? (int) $this->parent_id : null,
            'body' => (string) $this->body,

            // Compteurs & réactions
            'likes' => (int) $this->likes_count,
            'likesCount' => (int) $this->likes_count,
            'likes_count' => (int) $this->likes_count,
            'dislikes' => (int) $this->dislikes_count,
            'dislikesCount' => (int) $this->dislikes_count,
            'dislikes_count' => (int) $this->dislikes_count,
            'repliesCount' => (int) $this->replies_count,
            'replies_count' => (int) $this->replies_count,
            'myReaction' => $myReaction,
            'my_reaction' => $myReaction,

            // Flags d'état & permissions
            'mine' => $isMine,
            'isEdited' => (bool) $this->is_edited,
            'is_edited' => (bool) $this->is_edited,
            'canDelete' => $currentUser ? Gate::forUser($currentUser)->allows('delete', $this->resource) : false,

            // Auteur du commentaire
            'user' => [
                'id' => $author?->id,
                'name' => $author?->display_name ?? $author?->name ?? 'Utilisateur Bango',
                'displayName' => $author?->display_name ?? $author?->name ?? 'Utilisateur Bango',
                'handle' => $author?->handle,
                'avatarUrl' => $author?->avatar_url,
                'badge' => $author?->badge,
                'verified' => (bool) ($author?->is_verified ?? false),
            ],

            // Horodatage
            'createdAt' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
