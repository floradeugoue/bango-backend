<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Reel\ReelCommentReaction;
use App\Models\User;
use App\Services\Admin\AutomatedTextFilterService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReelCommentService
{
    /**
     * Liste les commentaires de premier niveau d'un Reel.
     */
    public function listComments(Reel $reel, ?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReelComment::query()
            ->with(['user'])
            ->where('reel_id', $reel->id)
            ->whereNull('parent_id')
            ->orderByDesc('created_at');

        if ($user) {
            $query->with(['reactions' => fn ($q) => $q->where('user_id', $user->id)]);
        }

        return $query->paginate($perPage);
    }

    /**
     * Liste les réponses à un commentaire.
     */
    public function getReplies(ReelComment $comment, ?User $user = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = ReelComment::query()
            ->with(['user'])
            ->where('parent_id', $comment->id)
            ->orderBy('created_at');

        if ($user) {
            $query->with(['reactions' => fn ($q) => $q->where('user_id', $user->id)]);
        }

        return $query->paginate($perPage);
    }

    /**
     * Ajoute un commentaire ou une réponse sur un Reel.
     */
    public function createComment(Reel $reel, User $user, string $body, ?int $parentId = null): ReelComment
    {
        if ($reel->comments_closed) {
            throw ValidationException::withMessages([
                'comment' => ['Les commentaires sont fermés pour ce Reel.'],
            ]);
        }

        if ($parentId) {
            $parent = ReelComment::where('id', $parentId)->where('reel_id', $reel->id)->first();
            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => ['Le commentaire parent spécifié est introuvable sur ce Reel.'],
                ]);
            }
        }

        return DB::transaction(function () use ($reel, $user, $body, $parentId) {
            $comment = ReelComment::create([
                'reel_id' => $reel->id,
                'user_id' => $user->id,
                'parent_id' => $parentId,
                'body' => trim($body),
            ]);

            $reel->increment('comments_count');

            if ($parentId) {
                ReelComment::where('id', $parentId)->increment('replies_count');
            }

            // Point 19 : Déclenchement du filtre textuel automatique sur le commentaire du Reel
            app(AutomatedTextFilterService::class)
                ->scanText($comment->body, 'reel_comment', $comment->id, $user);

            return $comment->load('user');
        });
    }

    /**
     * Modifie le texte d'un commentaire (réservé à son auteur).
     */
    public function updateComment(ReelComment $comment, User $user, string $body): ReelComment
    {
        if ($comment->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'comment' => ['Vous n\'êtes pas autorisé à modifier ce commentaire.'],
            ]);
        }

        $comment->update([
            'body' => trim($body),
            'is_edited' => true,
        ]);

        return $comment->load('user');
    }

    /**
     * Supprime un commentaire (réservé à l'auteur du commentaire ou du Reel).
     */
    public function deleteComment(ReelComment $comment, User $user): bool
    {
        $reel = $comment->reel;

        if ($comment->user_id !== $user->id && $reel->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'comment' => ['Vous n\'êtes pas autorisé à supprimer ce commentaire.'],
            ]);
        }

        return DB::transaction(function () use ($comment, $reel) {
            $parentId = $comment->parent_id;

            // Si c'est un commentaire parent, supprimer en cascade ses réponses
            $repliesCount = 0;
            if (! $parentId) {
                $replies = ReelComment::where('parent_id', $comment->id)->get();
                $repliesCount = $replies->count();
                foreach ($replies as $reply) {
                    $reply->delete();
                }
            }

            $deleted = $comment->delete();

            if ($deleted) {
                $totalToDecrement = 1 + $repliesCount;
                if ($reel->comments_count > 0) {
                    $reel->decrement('comments_count', min((int) $reel->comments_count, $totalToDecrement));
                }

                if ($parentId) {
                    $parent = ReelComment::find($parentId);
                    if ($parent && $parent->replies_count > 0) {
                        $parent->decrement('replies_count');
                    }
                }
            }

            return (bool) $deleted;
        });
    }

    /**
     * Réagit à un commentaire (like ou dislike avec exclusivité).
     *
     * @return array{
     *     user_reaction: string|null,
     *     likes_count: int,
     *     dislikes_count: int
     * }
     */
    public function reactToComment(ReelComment $comment, User $user, ReelReactionType $type): array
    {
        return DB::transaction(function () use ($comment, $user, $type) {
            $existing = ReelCommentReaction::where('comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                ReelCommentReaction::create([
                    'comment_id' => $comment->id,
                    'user_id' => $user->id,
                    'type' => $type,
                ]);

                if ($type === ReelReactionType::Like) {
                    $comment->increment('likes_count');
                } else {
                    $comment->increment('dislikes_count');
                }

                $userReaction = $type->value;
            } elseif ($existing->type === $type) {
                $existing->delete();

                if ($type === ReelReactionType::Like) {
                    $comment->decrement('likes_count');
                } else {
                    $comment->decrement('dislikes_count');
                }

                $userReaction = null;
            } else {
                $existing->update(['type' => $type]);

                if ($type === ReelReactionType::Like) {
                    $comment->increment('likes_count');
                    $comment->decrement('dislikes_count');
                } else {
                    $comment->increment('dislikes_count');
                    $comment->decrement('likes_count');
                }

                $userReaction = $type->value;
            }

            $comment->refresh();

            return [
                'user_reaction' => $userReaction,
                'likes_count' => (int) $comment->likes_count,
                'dislikes_count' => (int) $comment->dislikes_count,
            ];
        });
    }

    /**
     * Supprime une réaction sur un commentaire.
     *
     * @return array{
     *     user_reaction: null,
     *     likes_count: int,
     *     dislikes_count: int
     * }
     */
    public function removeCommentReaction(ReelComment $comment, User $user): array
    {
        return DB::transaction(function () use ($comment, $user) {
            $existing = ReelCommentReaction::where('comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->type === ReelReactionType::Like) {
                    $comment->decrement('likes_count');
                } else {
                    $comment->decrement('dislikes_count');
                }

                $existing->delete();
            }

            $comment->refresh();

            return [
                'user_reaction' => null,
                'likes_count' => (int) $comment->likes_count,
                'dislikes_count' => (int) $comment->dislikes_count,
            ];
        });
    }
}
