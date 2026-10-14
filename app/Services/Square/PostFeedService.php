<?php

namespace App\Services\Square;

use App\Enums\Square\PostAudience;
use App\Models\Profile\Follow;
use App\Models\Square\Post;
use App\Models\User;
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

        // Si consultation des posts d'un profil spécifique
        if ($authorId) {
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
            $query->where(function (Builder $q) use ($user) {
                if ($user->neighbourhood) {
                    $q->where('place_neighbourhood', $user->neighbourhood);
                }
                if ($user->city) {
                    $q->orWhere('place_city', $user->city);
                }
            });
        } else {
            // Onglet découverte / public : posts d'audience publique
            // ou posts de l'utilisateur connecté
            $query->where(function (Builder $q) use ($user) {
                $q->where('audience', PostAudience::Public->value);
                if ($user) {
                    $q->orWhere('user_id', $user->id);
                }
            });
        }

        return $query->paginate($perPage);
    }
}
