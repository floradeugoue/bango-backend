<?php

namespace App\Services\Square;

use App\Enums\Square\PostAudience;
use App\Models\Profile\Follow;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Boost\AdDeliveryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PostFeedService
{
    /**
     * Récupère le fil Square paginé selon le contexte utilisateur et le filtre demandé.
     *
     * @param  array{
     *     tab?: string, // 'discover' | 'following' | 'neighbourhood'
     *     author_id?: ?int,
     *     per_page?: int
     * }  $filters
     */
    public function getFeed(?User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 50);
        $tab = $filters['tab'] ?? 'discover';
        $authorId = isset($filters['author_id']) ? (int) $filters['author_id'] : null;

        $query = Post::query()
            ->with(['media.tags.user', 'hashtags', 'user'])
            ->orderByDesc('created_at');

        if ($user) {
            $query->with(['likes' => fn ($q) => $q->where('user_id', $user->id)]);
        }

        // Exclure les auteurs supprimés ou suspendus
        $query->whereHas('user', function (Builder $u) {
            $u->where('status', '!=', 'suspended');
        });

        // Exclure les utilisateurs bloqués si l'utilisateur est connecté
        if ($user) {
            $blockedIds = $user->blockedUsers()->pluck('blocked_id')
                ->merge($user->blockedByUsers()->pluck('blocker_id'))
                ->unique()
                ->all();

            if (! empty($blockedIds)) {
                $query->whereNotIn('user_id', $blockedIds);
            }
        }

        // Si consultation des posts d'un profil spécifique
        if ($authorId) {
            if ($user && (int) $user->id !== $authorId) {
                if ($user->hasBlocked($authorId) || $user->isBlockedBy($authorId)) {
                    $query->whereRaw('1 = 0');
                }
            }

            $query->where('user_id', $authorId);

            // Si c'est l'auteur lui-même, il peut voir ses archives si demandé, sinon posts actifs
            if ($user && (int) $user->id === $authorId && ($filters['archived'] ?? false)) {
                $query->where('is_archived', true);
            } else {
                $query->where('is_archived', false);
            }

            return $query->paginate($perPage);
        }

        // Flux général : masquer les posts archivés
        $query->where('is_archived', false);

        if ($tab === 'following' && $user) {
            // Uniquement les créateurs suivis + soi-même
            $followingIds = Follow::where('follower_id', $user->id)
                ->pluck('following_id')
                ->push($user->id)
                ->all();

            $query->whereIn('user_id', $followingIds);
        } elseif ($tab === 'neighbourhood' && $user) {
            // Posts dans le même quartier ou la même ville
            if (! $user->neighbourhood && ! $user->city) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $q) use ($user) {
                    if ($user->neighbourhood) {
                        $q->where('place_neighbourhood', $user->neighbourhood);
                    }
                    if ($user->city) {
                        $q->orWhere('place_city', $user->city);
                    }
                });
            }
        } else {
            $query->where(function (Builder $q) use ($user) {
                $q->where('audience', PostAudience::Public->value);
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                }
            });
        }

        $paginator = $query->paginate($perPage);

        // Injection publicitaire BO (onglets découverte ou neighbourhood, exclusion des profils auteurs)
        if (! $authorId && $tab !== 'following') {
            $paginator = app(AdDeliveryService::class)->injectIntoPostFeed($paginator, $user, $filters);
        }

        return $paginator;
    }
}
