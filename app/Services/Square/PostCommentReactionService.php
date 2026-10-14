<?php

namespace App\Services\Square;

use App\Models\Square\PostComment;
use App\Models\Square\PostCommentReaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PostCommentReactionService
{
    /**
     * Ajoute, modifie ou bascule (toggle) une réaction sur un commentaire.
     *
     * @return array{my_reaction: ?string, likes_count: int, dislikes_count: int}
     *
     * @throws AuthorizationException
     */
    public function react(PostComment $comment, User $user, string $type): array
    {
        if (! in_array($type, ['like', 'dislike'], true)) {
            throw new InvalidArgumentException("Type de réaction invalide ({$type}). Valeurs autorisées : like, dislike.");
        }

        if (Gate::forUser($user)->denies('react', $comment)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à réagir à ce commentaire.');
        }

        return DB::transaction(function () use ($comment, $user, $type) {
            /** @var PostComment $lockedComment */
            $lockedComment = PostComment::where('id', $comment->id)->lockForUpdate()->firstOrFail();

            /** @var PostCommentReaction|null $existing */
            $existing = PostCommentReaction::where('comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->first();

            $myReaction = null;

            if (! $existing) {
                // 1. Nouvelle réaction
                PostCommentReaction::create([
                    'comment_id' => $comment->id,
                    'user_id' => $user->id,
                    'type' => $type,
                ]);

                if ($type === 'like') {
                    $lockedComment->increment('likes_count');
                } else {
                    $lockedComment->increment('dislikes_count');
                }

                $myReaction = $type;
            } elseif ($existing->type === $type) {
                // 2. Même réaction répétée : retrait (toggle)
                $existing->delete();

                if ($type === 'like') {
                    if ($lockedComment->likes_count > 0) {
                        $lockedComment->decrement('likes_count');
                    }
                } else {
                    if ($lockedComment->dislikes_count > 0) {
                        $lockedComment->decrement('dislikes_count');
                    }
                }

                $myReaction = null;
            } else {
                // 3. Basculement d'un type à l'autre (ex: like -> dislike ou dislike -> like)
                $oldType = $existing->type;
                $existing->update(['type' => $type]);

                if ($oldType === 'like') {
                    if ($lockedComment->likes_count > 0) {
                        $lockedComment->decrement('likes_count');
                    }
                } else {
                    if ($lockedComment->dislikes_count > 0) {
                        $lockedComment->decrement('dislikes_count');
                    }
                }

                if ($type === 'like') {
                    $lockedComment->increment('likes_count');
                } else {
                    $lockedComment->increment('dislikes_count');
                }

                $myReaction = $type;
            }

            $lockedComment->refresh();

            return [
                'my_reaction' => $myReaction,
                'likes_count' => (int) $lockedComment->likes_count,
                'dislikes_count' => (int) $lockedComment->dislikes_count,
            ];
        });
    }

    /**
     * Supprime explicitement toute réaction de l'utilisateur sur le commentaire.
     *
     * @return array{my_reaction: ?string, likes_count: int, dislikes_count: int}
     *
     * @throws AuthorizationException
     */
    public function removeReaction(PostComment $comment, User $user): array
    {
        if (Gate::forUser($user)->denies('react', $comment)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à interagir avec ce commentaire.');
        }

        return DB::transaction(function () use ($comment, $user) {
            /** @var PostComment $lockedComment */
            $lockedComment = PostComment::where('id', $comment->id)->lockForUpdate()->firstOrFail();

            /** @var PostCommentReaction|null $existing */
            $existing = PostCommentReaction::where('comment_id', $comment->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                $type = $existing->type;
                $existing->delete();

                if ($type === 'like') {
                    if ($lockedComment->likes_count > 0) {
                        $lockedComment->decrement('likes_count');
                    }
                } else {
                    if ($lockedComment->dislikes_count > 0) {
                        $lockedComment->decrement('dislikes_count');
                    }
                }

                $lockedComment->refresh();
            }

            return [
                'my_reaction' => null,
                'likes_count' => (int) $lockedComment->likes_count,
                'dislikes_count' => (int) $lockedComment->dislikes_count,
            ];
        });
    }
}
