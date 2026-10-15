<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelReaction;
use App\Models\Reel\ReelShare;
use App\Models\Reel\ReelView;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        return DB::transaction(function () use ($reel, $user, $type) {
            $existing = ReelReaction::where('reel_id', $reel->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $existing) {
                // Création d'une nouvelle réaction
                ReelReaction::create([
                    'reel_id' => $reel->id,
                    'user_id' => $user->id,
                    'type' => $type,
                ]);

                if ($type === ReelReactionType::Like) {
                    $reel->increment('likes_count');
                } else {
                    $reel->increment('dislikes_count');
                }

                $userReaction = $type->value;
            } elseif ($existing->type === $type) {
                // Clic répété sur la même réaction : annulation / suppression
                $existing->delete();

                if ($type === ReelReactionType::Like) {
                    $reel->decrement('likes_count');
                } else {
                    $reel->decrement('dislikes_count');
                }

                $userReaction = null;
            } else {
                // Bascule d'une réaction à l'autre (ex: passage de Like à Dislike)
                $existing->update(['type' => $type]);

                if ($type === ReelReactionType::Like) {
                    $reel->increment('likes_count');
                    $reel->decrement('dislikes_count');
                } else {
                    $reel->increment('dislikes_count');
                    $reel->decrement('likes_count');
                }

                $userReaction = $type->value;
            }

            $reel->refresh();

            return [
                'user_reaction' => $userReaction,
                'likes_count' => (int) $reel->likes_count,
                'dislikes_count' => (int) $reel->dislikes_count,
            ];
        });
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
            $existing = ReelReaction::where('reel_id', $reel->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->type === ReelReactionType::Like) {
                    $reel->decrement('likes_count');
                } else {
                    $reel->decrement('dislikes_count');
                }

                $existing->delete();
            }

            $reel->refresh();

            return [
                'user_reaction' => null,
                'likes_count' => (int) $reel->likes_count,
                'dislikes_count' => (int) $reel->dislikes_count,
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
        $isSaved = $reel->savedByUsers()->where('users.id', $user->id)->exists();

        if ($isSaved) {
            $reel->savedByUsers()->detach($user->id);
            $next = false;
        } else {
            $reel->savedByUsers()->attach($user->id);
            $next = true;
        }

        return ['saved' => $next];
    }

    /**
     * Liste des Reels sauvegardés par l'utilisateur.
     */
    public function listSavedReels(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $user->savedReels()
            ->with(['user', 'sound'])
            ->orderByDesc('user_saved_reels.created_at')
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
