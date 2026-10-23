<?php

namespace App\Services\Square;

use App\Enums\Square\PostAudience;
use App\Models\Profile\Follow;
use App\Models\Square\Post;
use App\Models\User;
use App\Policies\PostPolicy;
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

        // Exclure les utilisateurs bloqués et masqués (mutes actifs) si l'utilisateur est connecté
        if ($user) {
            $blockedIds = $user->blockedUsers()->pluck('blocked_id')
                ->merge($user->blockedByUsers()->pluck('blocker_id'))
                ->merge($user->activeMutedUserIds())
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

            // Filtrer l'audience selon la relation avec l'auteur
            if (! $user || (int) $user->id !== $authorId) {
                $isFollowing = $user && Follow::where('follower_id', $user->id)->where('following_id', $authorId)->exists();
                $query->where(function (Builder $q) use ($user, $isFollowing) {
                    $q->where('audience', PostAudience::Public->value);
                    if ($isFollowing) {
                        $q->orWhere('audience', PostAudience::Followers->value);
                    }
                    if ($user && ($user->neighbourhood || $user->city)) {
                        $q->orWhere(function (Builder $loc) use ($user) {
                            $loc->where('audience', PostAudience::Neighbourhood->value);
                            if ($user->neighbourhood) {
                                $loc->where('place_neighbourhood', $user->neighbourhood);
                            }
                            if ($user->city) {
                                $loc->where('place_city', $user->city);
                            }
                        });
                    }
                });
            }

            $paginator = $query->paginate($perPage);
            $policy = app(PostPolicy::class);
            $paginator->setCollection($paginator->getCollection()->filter(fn ($post) => $policy->view($user, $post))->values());

            return $paginator;
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
            // Mon quartier uniquement (et la même ville) ; à défaut de lieu sur la publication, le quartier du profil de l'auteur
            $neighbourhood = $user->getRawOriginal('neighbourhood');
            $city = $user->getRawOriginal('city');

            if (! $neighbourhood) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $q) use ($neighbourhood, $city) {
                    $q->where(function (Builder $placed) use ($neighbourhood, $city) {
                        $placed->where('place_neighbourhood', $neighbourhood);
                        if ($city) {
                            $placed->where('place_city', $city);
                        }
                    })->orWhere(function (Builder $unplaced) use ($neighbourhood, $city) {
                        $unplaced->where(fn (Builder $empty) => $empty->whereNull('place_neighbourhood')->orWhere('place_neighbourhood', ''))
                            ->whereHas('user', function (Builder $author) use ($neighbourhood, $city) {
                                $author->where('neighbourhood', $neighbourhood);
                                if ($city) {
                                    $author->where('city', $city);
                                }
                            });
                    });
                });
            }
        } elseif ($tab === 'city' && $user) {
            // Toute ma ville : lieu de la publication, ou ville du profil de l'auteur à défaut de lieu
            $city = $user->getRawOriginal('city');

            if (! $city) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $q) use ($city) {
                    $q->where('place_city', $city)
                        ->orWhere(function (Builder $unplaced) use ($city) {
                            $unplaced->where(fn (Builder $empty) => $empty->whereNull('place_city')->orWhere('place_city', ''))
                                ->whereHas('user', fn (Builder $author) => $author->where('city', $city));
                        });
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

        // Appliquer PostPolicy sur chaque élément pour tous les onglets
        $policy = app(PostPolicy::class);
        $paginator->setCollection($paginator->getCollection()->filter(fn ($post) => $policy->view($user, $post))->values());

        // Injection publicitaire BO (onglets découverte ou neighbourhood, exclusion des profils auteurs)
        if (! $authorId && $tab !== 'following') {
            $paginator = app(AdDeliveryService::class)->injectIntoPostFeed($paginator, $user, $filters);
        }

        return $paginator;
    }
}
