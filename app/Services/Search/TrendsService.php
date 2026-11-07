<?php

namespace App\Services\Search;

use App\Models\Reel\Sound;
use App\Models\Square\Hashtag;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TrendsService
{
    /**
     * Durée du cache court pour les tendances unifiées (en secondes).
     */
    public const CACHE_TTL_SECONDS = 300;

    /**
     * Récupère les tendances unifiées (RC-11) composées des :
     * 1. Top hashtags (basé sur le volume des 7 derniers jours par pays avec évolution)
     * 2. Musiques du moment (sons actifs ordonnés par utilisation sur 7 jours avec évolution)
     * 3. Créateurs émergents (profils publics actifs filtrés par pays et ordonnés par engagement)
     *
     * @return array{top_hashtags: mixed, trending_sounds: mixed, emerging_creators: mixed}
     */
    public function getTrends(?User $viewer = null, ?string $country = null): array
    {
        $now = now();
        $sevenDaysAgo = $now->copy()->subDays(7);
        $fourteenDaysAgo = $now->copy()->subDays(14);
        $normalizedCountry = $country && trim($country) !== '' ? strtoupper(trim($country)) : null;

        // 1. Top hashtags des 7 derniers jours (filtré par pays si spécifié)
        $postsCurrentQuery = DB::table('post_hashtag')
            ->join('posts', 'post_hashtag.post_id', '=', 'posts.id')
            ->whereNull('posts.deleted_at')
            ->where('posts.created_at', '>=', $sevenDaysAgo)
            ->where('posts.created_at', '<=', $now);

        if ($normalizedCountry) {
            $postsCurrentQuery->join('users', 'posts.user_id', '=', 'users.id')
                ->where(function ($q) use ($normalizedCountry) {
                    $q->where('users.country_code', $normalizedCountry)
                        ->orWhere('users.country_code', strtolower($normalizedCountry));
                });
        }

        $recentCounts = $postsCurrentQuery
            ->select('post_hashtag.hashtag_id', DB::raw('COUNT(posts.id) as recent_posts_count'))
            ->groupBy('post_hashtag.hashtag_id')
            ->orderByDesc('recent_posts_count')
            ->limit(10)
            ->get()
            ->keyBy('hashtag_id');

        $hashtagIds = $recentCounts->keys()->all();

        $topHashtagsQuery = Hashtag::query()
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hashtag_exclusions')
                    ->where('is_active', true)
                    ->whereColumn('hashtag_exclusions.hashtag_name', 'hashtags.normalized_name');
            });

        if (! empty($hashtagIds)) {
            $topHashtags = $topHashtagsQuery->whereIn('id', $hashtagIds)->get();
        } else {
            // Fallback si aucune publication dans les 7 derniers jours
            $topHashtags = $topHashtagsQuery->where('posts_count', '>', 0)
                ->orderByDesc('posts_count')
                ->orderBy('normalized_name', 'asc')
                ->limit(10)
                ->get();
        }

        // Calcul des évolutions sur la semaine précédente (14j -> 7j)
        $postsPreviousQuery = DB::table('post_hashtag')
            ->join('posts', 'post_hashtag.post_id', '=', 'posts.id')
            ->whereNull('posts.deleted_at')
            ->where('posts.created_at', '>=', $fourteenDaysAgo)
            ->where('posts.created_at', '<', $sevenDaysAgo);

        if ($normalizedCountry) {
            $postsPreviousQuery->join('users', 'posts.user_id', '=', 'users.id')
                ->where(function ($q) use ($normalizedCountry) {
                    $q->where('users.country_code', $normalizedCountry)
                        ->orWhere('users.country_code', strtolower($normalizedCountry));
                });
        }

        $prevCounts = $postsPreviousQuery
            ->whereIn('post_hashtag.hashtag_id', $topHashtags->pluck('id'))
            ->select('post_hashtag.hashtag_id', DB::raw('COUNT(posts.id) as prev_posts_count'))
            ->groupBy('post_hashtag.hashtag_id')
            ->get()
            ->keyBy('hashtag_id');

        $topHashtags = $topHashtags->map(function ($ht) use ($recentCounts, $prevCounts) {
            $current = (int) ($recentCounts->get($ht->id)?->recent_posts_count ?? $ht->posts_count);
            $prev = (int) ($prevCounts->get($ht->id)?->prev_posts_count ?? 0);
            $ht->posts_count_7d = $current;
            $ht->previous_posts_count_7d = $prev;
            $ht->posts_count = $current;
            if ($prev > 0) {
                $ht->evolution_percentage = round((($current - $prev) / $prev) * 100, 1);
            } else {
                $ht->evolution_percentage = $current > 0 ? 100.0 : 0.0;
            }
            $ht->trend_direction = $current > $prev ? 'up' : ($current < $prev ? 'down' : 'stable');

            return $ht;
        })->sortByDesc('posts_count_7d')->values();

        // 2. Musiques du moment réelles (7 derniers jours et filtre pays)
        $reelsCurrentQuery = DB::table('reels')
            ->whereNull('reels.deleted_at')
            ->whereNotNull('reels.sound_id')
            ->where('reels.created_at', '>=', $sevenDaysAgo)
            ->where('reels.created_at', '<=', $now);

        if ($normalizedCountry) {
            $reelsCurrentQuery->join('users', 'reels.user_id', '=', 'users.id')
                ->where(function ($q) use ($normalizedCountry) {
                    $q->where('users.country_code', $normalizedCountry)
                        ->orWhere('users.country_code', strtolower($normalizedCountry));
                });
        }

        $recentSoundCounts = $reelsCurrentQuery
            ->select('reels.sound_id', DB::raw('COUNT(reels.id) as recent_uses_count'))
            ->groupBy('reels.sound_id')
            ->orderByDesc('recent_uses_count')
            ->limit(10)
            ->get()
            ->keyBy('sound_id');

        $soundIds = $recentSoundCounts->keys()->all();

        $soundsQuery = Sound::query()->availableForUsers();
        if (! empty($soundIds)) {
            $trendingSounds = $soundsQuery->whereIn('id', $soundIds)->get();
        } else {
            $trendingSounds = $soundsQuery->orderByDesc('uses_count')
                ->orderByDesc('base_uses')
                ->limit(10)
                ->get();
        }

        // Évolution des sons sur la période précédente
        $reelsPrevQuery = DB::table('reels')
            ->whereNull('reels.deleted_at')
            ->whereNotNull('reels.sound_id')
            ->where('reels.created_at', '>=', $fourteenDaysAgo)
            ->where('reels.created_at', '<', $sevenDaysAgo);

        if ($normalizedCountry) {
            $reelsPrevQuery->join('users', 'reels.user_id', '=', 'users.id')
                ->where(function ($q) use ($normalizedCountry) {
                    $q->where('users.country_code', $normalizedCountry)
                        ->orWhere('users.country_code', strtolower($normalizedCountry));
                });
        }

        $prevSoundCounts = $reelsPrevQuery
            ->whereIn('reels.sound_id', $trendingSounds->pluck('id'))
            ->select('reels.sound_id', DB::raw('COUNT(reels.id) as prev_uses_count'))
            ->groupBy('reels.sound_id')
            ->get()
            ->keyBy('sound_id');

        $trendingSounds = $trendingSounds->map(function ($snd) use ($recentSoundCounts, $prevSoundCounts) {
            $current = (int) ($recentSoundCounts->get($snd->id)?->recent_uses_count ?? $snd->uses_count);
            $prev = (int) ($prevSoundCounts->get($snd->id)?->prev_uses_count ?? 0);
            $snd->recent_uses_count = $current;
            $snd->previous_uses_count = $prev;
            if ($prev > 0) {
                $snd->evolution_percentage = round((($current - $prev) / $prev) * 100, 1);
            } else {
                $snd->evolution_percentage = $current > 0 ? 100.0 : 0.0;
            }
            $snd->trend_direction = $current > $prev ? 'up' : ($current < $prev ? 'down' : 'stable');

            return $snd;
        })->sortByDesc('recent_uses_count')->values();

        // 3. Créateurs émergents réels
        $creatorsQuery = User::query()
            ->where('status', 'active')
            ->where('is_private', false)
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNotNull('badge')
                        ->where('badge', '!=', 'none')
                        ->where('verification_lapsed', false);
                })
                    ->orWhere('account_type', 'pro')
                    ->orWhereHas('posts')
                    ->orWhereHas('reels');
            });

        if ($normalizedCountry) {
            $creatorsQuery->where(function ($q) use ($normalizedCountry) {
                $q->where('users.country_code', $normalizedCountry)
                    ->orWhere('users.country_code', strtolower($normalizedCountry));
            });
        }

        // Exclusion des blocages si authentifié
        if ($viewer) {
            $creatorsQuery->where('users.id', '!=', $viewer->id)
                ->whereNotExists(function ($q) use ($viewer) {
                    $q->select(DB::raw(1))
                        ->from('user_blocks')
                        ->where(function ($sub) use ($viewer) {
                            $sub->where('user_blocks.blocker_id', $viewer->id)
                                ->whereColumn('user_blocks.blocked_id', 'users.id');
                        })
                        ->orWhere(function ($sub) use ($viewer) {
                            $sub->whereColumn('user_blocks.blocker_id', 'users.id')
                                ->where('user_blocks.blocked_id', $viewer->id);
                        });
                });
        }

        $emergingCreators = $creatorsQuery
            ->withCount(['followers', 'posts', 'reels'])
            ->orderByDesc('followers_count')
            ->orderByDesc('reels_count')
            ->orderByDesc('posts_count')
            ->limit(10)
            ->get();

        return [
            'top_hashtags' => $topHashtags,
            'trending_sounds' => $trendingSounds,
            'emerging_creators' => $emergingCreators,
        ];
    }
}
