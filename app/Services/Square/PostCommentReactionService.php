<?php

namespace App\Services\Square;

use App\Models\Square\PostComment;
use App\Models\Square\PostCommentReaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PostCommentReactionService
{
    /**
     * Ajoute, modifie ou bascule (toggle) une réaction sur un commentaire.
     *
     * @return array{my_reaction: ?string, user_reaction: ?string, likes_count: int, dislikes_count: int}
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

        $activePage = request()?->attributes?->get('acting_page') ?? request()?->attributes?->get('active_page');
        $activePageId = $activePage?->id;

        try {
            return DB::transaction(function () use ($comment, $user, $type, $activePageId) {
                /** @var PostComment $lockedComment */
                $lockedComment = PostComment::where('id', $comment->id)->lockForUpdate()->firstOrFail();

                /** @var PostCommentReaction|null $existing */
                $existing = PostCommentReaction::where('comment_id', $lockedComment->id)
                    ->where('user_id', $user->id)
                    ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                    ->lockForUpdate()
                    ->first();

                $myReaction = null;

                if (! $existing) {
                    try {
                        PostCommentReaction::create([
                            'comment_id' => $lockedComment->id,
                            'user_id' => $user->id,
                            'page_id' => $activePageId,
                            'type' => $type,
                        ]);

                        if ($type === 'like') {
                            $lockedComment->increment('likes_count');
                        } else {
                            $lockedComment->increment('dislikes_count');
                        }

                        $myReaction = $type;
                    } catch (QueryException) {
                        $existing = PostCommentReaction::where('comment_id', $lockedComment->id)
                            ->where('user_id', $user->id)
                            ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                            ->first();
                        $myReaction = $existing?->type;

                        $lockedComment->likes_count = PostCommentReaction::where('comment_id', $lockedComment->id)->where('type', 'like')->count();
                        $lockedComment->dislikes_count = PostCommentReaction::where('comment_id', $lockedComment->id)->where('type', 'dislike')->count();
                        $lockedComment->saveQuietly();
                    }
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

                return [
                    'my_reaction' => $myReaction,
                    'user_reaction' => $myReaction,
                    'likes_count' => max(0, (int) $lockedComment->likes_count),
                    'dislikes_count' => max(0, (int) $lockedComment->dislikes_count),
                ];
            });
        } catch (QueryException) {
            $freshComment = PostComment::find($comment->id) ?? $comment;
            $existing = PostCommentReaction::where('comment_id', $freshComment->id)
                ->where('user_id', $user->id)
                ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                ->first();

            $likesCount = PostCommentReaction::where('comment_id', $freshComment->id)
                ->where('type', 'like')
                ->count();
            $dislikesCount = PostCommentReaction::where('comment_id', $freshComment->id)
                ->where('type', 'dislike')
                ->count();

            $freshComment->forceFill([
                'likes_count' => $likesCount,
                'dislikes_count' => $dislikesCount,
            ])->saveQuietly();

            return [
                'my_reaction' => $existing?->type,
                'user_reaction' => $existing?->type,
                'likes_count' => $likesCount,
                'dislikes_count' => $dislikesCount,
            ];
        }
    }

    /**
     * Supprime explicitement toute réaction de l'utilisateur sur le commentaire.
     *
     * @return array{my_reaction: ?string, user_reaction: ?string, likes_count: int, dislikes_count: int}
     *
     * @throws AuthorizationException
     */
    public function removeReaction(PostComment $comment, User $user): array
    {
        if (Gate::forUser($user)->denies('react', $comment)) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à interagir avec ce commentaire.');
        }

        $activePage = request()?->attributes?->get('acting_page') ?? request()?->attributes?->get('active_page');
        $activePageId = $activePage?->id;

        return DB::transaction(function () use ($comment, $user, $activePageId) {
            /** @var PostComment $lockedComment */
            $lockedComment = PostComment::where('id', $comment->id)->lockForUpdate()->firstOrFail();

            /** @var PostCommentReaction|null $existing */
            $existing = PostCommentReaction::where('comment_id', $lockedComment->id)
                ->where('user_id', $user->id)
                ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                ->lockForUpdate()
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
                'user_reaction' => null,
                'likes_count' => max(0, (int) $lockedComment->likes_count),
                'dislikes_count' => max(0, (int) $lockedComment->dislikes_count),
            ];
        });
    }
}
