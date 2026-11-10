<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelReaction;
use App\Models\Reel\ReelShare;
use App\Models\Reel\ReelView;
use App\Models\User;
use App\Notifications\Social\SocialNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ReelInteractionService
{
    /**
     * Applique une réaction (like ou dislike) sur un Reel en respectant l'exclusivité stricte.
     *
     * @return array{
     *     user_reaction: string|null,
     *     likes_count: int,
     *     dislikes_count: int
     * }
     */
    public function react(Reel $reel, User $user, ReelReactionType $type): array
    {
        $activePage = request()?->attributes?->get('active_page');

        try {
            return DB::transaction(function () use ($reel, $user, $type, $activePage) {
                /** @var Reel $lockedReel */
                $lockedReel = Reel::lockForUpdate()->find($reel->id) ?? $reel;

                $query = ReelReaction::where('reel_id', $lockedReel->id);
                if ($activePage) {
                    $query->where('page_id', $activePage->id);
                } else {
                    $query->where('user_id', $user->id)->whereNull('page_id');
                }

                $existing = $query->lockForUpdate()->first();

                if (! $existing) {
                    try {
                        ReelReaction::create([
                            'reel_id' => $lockedReel->id,
                            'user_id' => $user->id,
                            'page_id' => $activePage?->id,
                            'type' => $type,
                        ]);

                        if ($type === ReelReactionType::Like) {
                            $lockedReel->increment('likes_count');
                            if ($lockedReel->user_id && $lockedReel->user_id !== $user->id) {
                                $author = User::find($lockedReel->user_id);
                                if ($author) {
                                    $targetPageId = $lockedReel->page_id ?? null;
                                    $author->notify(new SocialNotification('like', $user, [
                                        'reel_id' => (string) $lockedReel->id,
                                        'page_id' => $targetPageId,
                                        'actor_page_id' => $activePage?->id,
                                    ]));
                                }
                            }
                        } else {
                            $lockedReel->increment('dislikes_count');
                        }

                        $userReaction = $type->value;
                    } catch (QueryException) {
                        $existingQuery = ReelReaction::where('reel_id', $lockedReel->id);
                        if ($activePage) {
                            $existingQuery->where('page_id', $activePage->id);
                        } else {
                            $existingQuery->where('user_id', $user->id)->whereNull('page_id');
                        }
                        $existing = $existingQuery->first();
                        $userReaction = $existing
                            ? ($existing->type instanceof ReelReactionType ? $existing->type->value : (string) $existing->type)
                            : null;
                    }
                } elseif ($existing->type === $type) {
                    $existing->delete();

                    if ($type === ReelReactionType::Like) {
                        if ($lockedReel->likes_count > 0) {
                            $lockedReel->decrement('likes_count');
                        }
                    } else {
                        if ($lockedReel->dislikes_count > 0) {
                            $lockedReel->decrement('dislikes_count');
                        }
                    }

                    $userReaction = null;
                } else {
                    $existing->update(['type' => $type]);

                    if ($type === ReelReactionType::Like) {
                        $lockedReel->increment('likes_count');
                        if ($lockedReel->dislikes_count > 0) {
                            $lockedReel->decrement('dislikes_count');
                        }
                    } else {
                        $lockedReel->increment('dislikes_count');
                        if ($lockedReel->likes_count > 0) {
                            $lockedReel->decrement('likes_count');
                        }
                    }

                    $userReaction = $type->value;
                }

                $lockedReel->refresh();

                return [
                    'user_reaction' => $userReaction,
                    'likes_count' => max(0, (int) $lockedReel->likes_count),
                    'dislikes_count' => max(0, (int) $lockedReel->dislikes_count),
                ];
            });
        } catch (QueryException) {
            $freshReel = Reel::find($reel->id) ?? $reel;
            $existingQuery = ReelReaction::where('reel_id', $freshReel->id);
            if ($activePage) {
                $existingQuery->where('page_id', $activePage->id);
            } else {
                $existingQuery->where('user_id', $user->id)->whereNull('page_id');
            }
            $existing = $existingQuery->first();

            return [
                'user_reaction' => $existing
                    ? ($existing->type instanceof ReelReactionType ? $existing->type->value : (string) $existing->type)
                    : null,
                'likes_count' => max(0, (int) $freshReel->likes_count),
                'dislikes_count' => max(0, (int) $freshReel->dislikes_count),
            ];
        }
    }

    /**
     * Supprime toute réaction active (like ou dislike) de l'utilisateur sur le Reel.
     *
     * @return array{
     *     user_reaction: null,
     *     likes_count: int,
     *     dislikes_count: int
     * }
     */
    public function removeReaction(Reel $reel, User $user): array
    {
        return DB::transaction(function () use ($reel, $user) {
            /** @var Reel $lockedReel */
            $lockedReel = Reel::lockForUpdate()->find($reel->id) ?? $reel;

            $existing = ReelReaction::where('reel_id', $lockedReel->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->type === ReelReactionType::Like) {
                    if ($lockedReel->likes_count > 0) {
                        $lockedReel->decrement('likes_count');
                    }
                } else {
                    if ($lockedReel->dislikes_count > 0) {
                        $lockedReel->decrement('dislikes_count');
                    }
                }

                $existing->delete();
            }

            $lockedReel->refresh();

            return [
                'user_reaction' => null,
                'likes_count' => max(0, (int) $lockedReel->likes_count),
                'dislikes_count' => max(0, (int) $lockedReel->dislikes_count),
            ];
        });
    }

    /**
     * Enregistre un partage de Reel de manière atomique et idempotente.
     *
     * @return array{shares_count: int}
     */
    public function recordShare(
        Reel $reel,
        User $user,
        ?string $platform = null,
        ?string $clientMutationId = null
    ): array {
        return DB::transaction(function () use ($reel, $user, $platform, $clientMutationId) {
            $lockedReel = Reel::where('id', $reel->id)->lockForUpdate()->first();
            if (! $lockedReel) {
                return ['shares_count' => (int) $reel->shares_count];
            }

            // Protection anti-doublon si client_mutation_id est fourni
            if ($clientMutationId) {
                $alreadyShared = ReelShare::where('reel_id', $lockedReel->id)
                    ->where('user_id', $user->id)
                    ->where('client_mutation_id', $clientMutationId)
                    ->exists();

                if ($alreadyShared) {
                    return ['shares_count' => (int) $lockedReel->shares_count];
                }
            }

            ReelShare::create([
                'reel_id' => $lockedReel->id,
                'user_id' => $user->id,
                'platform' => $platform,
                'client_mutation_id' => $clientMutationId,
            ]);

            $lockedReel->increment('shares_count');
            $lockedReel->refresh();

            return ['shares_count' => (int) $lockedReel->shares_count];
        });
    }

    /**
     * Enregistre une vue sur un Reel avec protection contre les incréments abusifs.
     * Une vue n'est comptabilisée qu'une fois par fenêtre temporelle (30 minutes) pour un même utilisateur ou IP.
     * L'adresse IP est anonymisée par empreinte cryptographique salée (RGPD / protection de la vie privée).
     *
     * @return array{viewed: bool, views_count: int}
     */
    public function recordView(Reel $reel, ?User $user = null, ?string $ipAddress = null): array
    {
        $window = now()->subMinutes(30);
        $anonymizedIp = $ipAddress ? substr(hash('sha256', $ipAddress.config('app.key')), 0, 45) : null;

        $alreadyViewed = ReelView::where('reel_id', $reel->id)
            ->where('viewed_at', '>=', $window)
            ->where(function ($q) use ($user, $anonymizedIp) {
                if ($user) {
                    $q->where('user_id', $user->id);
                } elseif ($anonymizedIp) {
                    $q->where('ip_address', $anonymizedIp);
                } else {
                    $q->whereRaw('1 = 0');
                }
            })
            ->exists();

        if ($alreadyViewed) {
            return [
                'viewed' => false,
                'views_count' => (int) $reel->views_count,
            ];
        }

        ReelView::create([
            'reel_id' => $reel->id,
            'user_id' => $user?->id,
            'ip_address' => $anonymizedIp,
            'viewed_at' => now(),
        ]);

        $reel->increment('views_count');
        $reel->refresh();

        return [
            'viewed' => true,
            'views_count' => (int) $reel->views_count,
        ];
    }

    /**
     * Bascule l'état de sauvegarde d'un Reel dans les favoris de l'utilisateur.
     *
     * @return array{saved: bool}
     */
    public function toggleSave(Reel $reel, User $user): array
    {
        $activePage = request()?->attributes?->get('active_page');

        return DB::transaction(function () use ($reel, $user, $activePage) {
            $lockedReel = Reel::where('id', $reel->id)->lockForUpdate()->first() ?? $reel;

            if ($activePage) {
                $record = DB::table('user_saved_reels')
                    ->where('reel_id', $lockedReel->id)
                    ->where('page_id', $activePage->id)
                    ->first();

                if ($record) {
                    DB::table('user_saved_reels')
                        ->where('reel_id', $lockedReel->id)
                        ->where('page_id', $activePage->id)
                        ->delete();
                    $lockedReel->decrement('saves_count');
                    $next = false;
                } else {
                    DB::table('user_saved_reels')->insert([
                        'user_id' => $user->id,
                        'reel_id' => $lockedReel->id,
                        'page_id' => $activePage->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $lockedReel->increment('saves_count');
                    $next = true;
                }
            } else {
                $isSaved = $lockedReel->savedByUsers()->where('users.id', $user->id)->exists();

                if ($isSaved) {
                    $lockedReel->savedByUsers()->detach($user->id);
                    $lockedReel->decrement('saves_count');
                    $next = false;
                } else {
                    $lockedReel->savedByUsers()->attach($user->id);
                    $lockedReel->increment('saves_count');
                    $next = true;
                }
            }

            $currentCount = (int) Reel::where('id', $lockedReel->id)->value('saves_count');

            return [
                'saved' => $next,
                'saves_count' => $currentCount,
            ];
        });
    }

    /**
     * Liste des Reels sauvegardés par l'utilisateur ou la page.
     */
    public function listSavedReels(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $activePage = request()?->attributes?->get('active_page');

        if ($activePage) {
            return Reel::query()
                ->join('user_saved_reels', 'reels.id', '=', 'user_saved_reels.reel_id')
                ->where('user_saved_reels.page_id', $activePage->id)
                ->select('reels.*')
                ->with(['user', 'sound'])
                ->orderByDesc('user_saved_reels.created_at')
                ->paginate($perPage);
        }

        return Reel::query()
            ->join('user_saved_reels', 'reels.id', '=', 'user_saved_reels.reel_id')
            ->where('user_saved_reels.user_id', $user->id)
            ->whereNull('user_saved_reels.page_id')
            ->select('reels.*')
            ->with(['user', 'sound'])
            ->orderByDesc('user_saved_reels.created_at')
            ->paginate($perPage);
    }

    /**
     * Liste des Reels aimés par l'utilisateur ou la page.
     */
    public function listLikedReels(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $activePage = request()?->attributes?->get('active_page');

        if ($activePage) {
            return Reel::query()
                ->join('reel_reactions', 'reels.id', '=', 'reel_reactions.reel_id')
                ->where('reel_reactions.page_id', $activePage->id)
                ->where('reel_reactions.type', ReelReactionType::Like->value)
                ->select('reels.*')
                ->with(['user', 'sound'])
                ->orderByDesc('reel_reactions.created_at')
                ->paginate($perPage);
        }

        return $user->likedReels()
            ->with(['user', 'sound'])
            ->orderByDesc('reel_reactions.created_at')
            ->paginate($perPage);
    }

    /**
     * Marque un Reel comme "Ça ne m'intéresse pas" pour l'utilisateur.
     *
     * @return array{not_interested: bool}
     */
    public function markNotInterested(Reel $reel, User $user): array
    {
        $exists = $reel->notInterestedByUsers()->where('users.id', $user->id)->exists();

        if (! $exists) {
            $reel->notInterestedByUsers()->attach($user->id);
        }

        return ['not_interested' => true];
    }
}
