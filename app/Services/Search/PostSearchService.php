<?php

namespace App\Services\Search;

use App\Enums\Square\PostAudience;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PostSearchService
{
    /**
     * Recherche textuelle dans les légendes des publications Square avec classement par pertinence.
     * Applique les filtres de visibilité, de confidentialité et les exclusions de blocage.
     */
    public function search(string $query, ?User $viewer = null, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 50);
        $cleanQuery = trim($query);

        $driver = DB::getDriverName();
        $baseQuery = Post::query()
            ->active()
            ->whereNull('deleted_at')
            ->whereHas('user', function (Builder $q) {
                $q->where('status', 'active')->whereNull('deleted_at');
            })
            ->with([
                'user',
                'page',
                'media.tags.user',
            ]);

        // 1. Filtrage plein texte et classement par pertinence
        if ($driver === 'pgsql') {
            $baseQuery->where(function (Builder $q) use ($cleanQuery) {
                $q->whereRaw("to_tsvector('french', coalesce(body, '')) @@ websearch_to_tsquery('french', ?)", [$cleanQuery])
                    ->orWhere('body', 'ILIKE', "%{$cleanQuery}%");
            });

            $baseQuery->select('posts.*')
                ->selectRaw("ts_rank(to_tsvector('french', coalesce(body, '')), websearch_to_tsquery('french', ?)) as search_rank", [$cleanQuery])
                ->orderByDesc('search_rank')
                ->orderByDesc('likes_count')
                ->orderByDesc('created_at');
        } else {
            // Driver compatible (SQLite / tests)
            $normalized = mb_strtolower($cleanQuery, 'UTF-8');
            $baseQuery->where(function (Builder $q) use ($normalized) {
                $q->where(DB::raw('LOWER(body)'), 'LIKE', "%{$normalized}%");
            });

            $escaped = str_replace("'", "''", $normalized);
            $baseQuery->orderByDesc(DB::raw("CASE WHEN LOWER(body) LIKE '{$escaped}%' THEN 2 WHEN LOWER(body) LIKE '%{$escaped}%' THEN 1 ELSE 0 END"))
                ->orderByDesc('likes_count')
                ->orderByDesc('created_at');
        }

        // 2. Filtrage de confidentialité et d'audience
        $baseQuery->where(function (Builder $q) use ($viewer) {
            // Publications publiques
            $q->where('audience', PostAudience::Public);

            if ($viewer) {
                // Mes propres publications
                $q->orWhere('posts.user_id', $viewer->id);

                // Publications réservées aux followers
                $q->orWhere(function (Builder $sub) use ($viewer) {
                    $sub->where('audience', PostAudience::Followers)
                        ->whereExists(function ($followQuery) use ($viewer) {
                            $followQuery->select(DB::raw(1))
                                ->from('follows')
                                ->where('follows.follower_id', $viewer->id)
                                ->whereColumn('follows.following_id', 'posts.user_id');
                        });
                });

                // Publications quartier / ville
                $q->orWhere(function (Builder $sub) use ($viewer) {
                    $sub->where('audience', PostAudience::Neighbourhood)
                        ->where(function ($placeQuery) use ($viewer) {
                            $hasCondition = false;
                            if ($viewer->neighbourhood) {
                                $placeQuery->where('posts.place_neighbourhood', $viewer->neighbourhood);
                                $hasCondition = true;
                            }
                            if ($viewer->city) {
                                if ($hasCondition) {
                                    $placeQuery->orWhere('posts.place_city', $viewer->city);
                                } else {
                                    $placeQuery->where('posts.place_city', $viewer->city);
                                }
                                $hasCondition = true;
                            }
                            if (! $hasCondition) {
                                $placeQuery->whereRaw('1 = 0');
                            }
                        });
                });
            }
        });

        // 3. Exclusion des utilisateurs bloqués ou ayant bloqué le viewer
        if ($viewer) {
            $baseQuery->whereNotExists(function ($q) use ($viewer) {
                $q->select(DB::raw(1))
                    ->from('user_blocks')
                    ->where(function ($sub) use ($viewer) {
                        $sub->where('user_blocks.blocker_id', $viewer->id)
                            ->whereColumn('user_blocks.blocked_id', 'posts.user_id');
                    })
                    ->orWhere(function ($sub) use ($viewer) {
                        $sub->whereColumn('user_blocks.blocker_id', 'posts.user_id')
                            ->where('user_blocks.blocked_id', $viewer->id);
                    });
            });
        }

        return $baseQuery->paginate($perPage);
    }
}
