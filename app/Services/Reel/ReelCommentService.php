<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Reel\ReelCommentReaction;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use App\Services\Admin\AutomatedTextFilterService;
use App\Services\Translation\TranslationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
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
        $author = $reel->relationLoaded('user') ? $reel->user : User::find($reel->user_id);
        if ($author && ! $author->canBeCommentedBy($user)) {
            abort(403, 'Cet utilisateur n\'autorise pas les commentaires.');
        }

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

        $activePage = request()?->attributes?->get('active_page');

        return DB::transaction(function () use ($reel, $user, $body, $parentId, $activePage) {
            $trimmedBody = trim($body);
            $language = app(TranslationService::class)->detectLanguage($trimmedBody);

            $comment = ReelComment::create([
                'reel_id' => $reel->id,
                'user_id' => $user->id,
                'page_id' => $activePage?->id,
                'parent_id' => $parentId,
                'body' => $trimmedBody,
                'language' => $language,
            ]);

            $reel->increment('comments_count');

            if ($parentId) {
                ReelComment::where('id', $parentId)->increment('replies_count');
            }

            // Point 19 : Déclenchement du filtre textuel automatique sur le commentaire du Reel
            app(AutomatedTextFilterService::class)
                ->scanText($comment->body, 'reel_comment', $comment->id, $user);

            // Notifications sociales : commentaire ou réponse
            $targetPageId = $reel->page_id ?? null;
            if ($parentId && isset($parent) && $parent->user_id !== $user->id) {
                $parentAuthor = User::find($parent->user_id);
                if ($parentAuthor) {
                    $parentAuthor->notify(new SocialNotification('reply', $user, [
                        'reel_id' => (string) $reel->id,
                        'comment_id' => (string) $comment->id,
                        'page_id' => $targetPageId,
                        'text' => $body,
                    ]));
                }
            } elseif (! $parentId && $reel->user_id && $reel->user_id !== $user->id) {
                $reelAuthor = User::find($reel->user_id);
                if ($reelAuthor) {
                    $reelAuthor->notify(new SocialNotification('comment', $user, [
                        'reel_id' => (string) $reel->id,
                        'comment_id' => (string) $comment->id,
                        'page_id' => $targetPageId,
                        'text' => $body,
                    ]));
                }
            }

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
        $activePage = request()?->attributes?->get('acting_page') ?? request()?->attributes?->get('active_page');
        $activePageId = $activePage?->id;

        try {
            return DB::transaction(function () use ($comment, $user, $type, $activePageId) {
                /** @var ReelComment $lockedComment */
                $lockedComment = ReelComment::lockForUpdate()->find($comment->id) ?? $comment;

                $existing = ReelCommentReaction::where('comment_id', $lockedComment->id)
                    ->where('user_id', $user->id)
                    ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                    ->lockForUpdate()
                    ->first();

                if (! $existing) {
                    try {
                        ReelCommentReaction::create([
                            'comment_id' => $lockedComment->id,
                            'user_id' => $user->id,
                            'page_id' => $activePageId,
                            'type' => $type,
                        ]);

                        if ($type === ReelReactionType::Like) {
                            $lockedComment->increment('likes_count');
                        } else {
                            $lockedComment->increment('dislikes_count');
                        }

                        $userReaction = $type->value;
                    } catch (Throwable) {
                        $existing = ReelCommentReaction::where('comment_id', $lockedComment->id)
                            ->where('user_id', $user->id)
                            ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                            ->first();
                        $userReaction = $existing
                            ? ($existing->type instanceof ReelReactionType ? $existing->type->value : (string) $existing->type)
                            : null;

                        // En cas de collision concurrente, s'assurer que les compteurs reflètent la réalité
                        $lockedComment->likes_count = ReelCommentReaction::where('comment_id', $lockedComment->id)->where('type', ReelReactionType::Like->value)->count();
                        $lockedComment->dislikes_count = ReelCommentReaction::where('comment_id', $lockedComment->id)->where('type', ReelReactionType::Dislike->value)->count();
                        $lockedComment->saveQuietly();
                    }
                } elseif ($existing->type === $type) {
                    $existing->delete();

                    if ($type === ReelReactionType::Like) {
                        if ($lockedComment->likes_count > 0) {
                            $lockedComment->decrement('likes_count');
                        }
                    } else {
                        if ($lockedComment->dislikes_count > 0) {
                            $lockedComment->decrement('dislikes_count');
                        }
                    }

                    $userReaction = null;
                } else {
                    $existing->update(['type' => $type]);

                    if ($type === ReelReactionType::Like) {
                        $lockedComment->increment('likes_count');
                        if ($lockedComment->dislikes_count > 0) {
                            $lockedComment->decrement('dislikes_count');
                        }
                    } else {
                        $lockedComment->increment('dislikes_count');
                        if ($lockedComment->likes_count > 0) {
                            $lockedComment->decrement('likes_count');
                        }
                    }

                    $userReaction = $type->value;
                }

                return [
                    'user_reaction' => $userReaction,
                    'likes_count' => max(0, (int) $lockedComment->likes_count),
                    'dislikes_count' => max(0, (int) $lockedComment->dislikes_count),
                ];
            });
        } catch (QueryException) {
            $freshComment = ReelComment::find($comment->id) ?? $comment;
            $existing = ReelCommentReaction::where('comment_id', $freshComment->id)
                ->where('user_id', $user->id)
                ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                ->first();

            $likesCount = ReelCommentReaction::where('comment_id', $freshComment->id)
                ->where('type', ReelReactionType::Like->value)
                ->count();
            $dislikesCount = ReelCommentReaction::where('comment_id', $freshComment->id)
                ->where('type', ReelReactionType::Dislike->value)
                ->count();

            $freshComment->forceFill([
                'likes_count' => $likesCount,
                'dislikes_count' => $dislikesCount,
            ])->saveQuietly();

            return [
                'user_reaction' => $existing
                    ? ($existing->type instanceof ReelReactionType ? $existing->type->value : (string) $existing->type)
                    : null,
                'likes_count' => $likesCount,
                'dislikes_count' => $dislikesCount,
            ];
        }
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
        $activePage = request()?->attributes?->get('acting_page') ?? request()?->attributes?->get('active_page');
        $activePageId = $activePage?->id;

        return DB::transaction(function () use ($comment, $user, $activePageId) {
            /** @var ReelComment $lockedComment */
            $lockedComment = ReelComment::lockForUpdate()->find($comment->id) ?? $comment;

            $existing = ReelCommentReaction::where('comment_id', $lockedComment->id)
                ->where('user_id', $user->id)
                ->when($activePageId, fn ($q) => $q->where('page_id', $activePageId), fn ($q) => $q->whereNull('page_id'))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->type === ReelReactionType::Like) {
                    if ($lockedComment->likes_count > 0) {
                        $lockedComment->decrement('likes_count');
                    }
                } else {
                    if ($lockedComment->dislikes_count > 0) {
                        $lockedComment->decrement('dislikes_count');
                    }
                }

                $existing->delete();
            }

            $lockedComment->refresh();

            return [
                'user_reaction' => null,
                'likes_count' => max(0, (int) $lockedComment->likes_count),
                'dislikes_count' => max(0, (int) $lockedComment->dislikes_count),
            ];
        });
    }
}
