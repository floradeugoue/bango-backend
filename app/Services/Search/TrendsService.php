<?php

namespace App\Services\Search;

use App\Models\Reel\Sound;
use App\Models\Square\Hashtag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
     * 1. Top hashtags (basé sur le volume réel posts_count)
     * 2. Musiques du moment (sons actifs et publiés ordonnés par utilisation)
     * 3. Créateurs émergents (profils publics actifs ordonnés par engagement et abonnés)
     *
     * @return array{top_hashtags: Collection, trending_sounds: Collection, emerging_creators: Collection}
     */
    public function getTrends(?User $viewer = null): array
    {
        // 1. Top hashtags réels (avec exclusion des hashtags modérés/exclus)
        $topHashtags = Hashtag::query()
            ->where('posts_count', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('hashtag_exclusions')
                    ->where('is_active', true)
                    ->whereColumn('hashtag_exclusions.hashtag_name', 'hashtags.normalized_name');
            })
            ->orderByDesc('posts_count')
            ->orderBy('normalized_name', 'asc')
            ->limit(10)
            ->get();

        // 2. Musiques du moment réelles (RL)
        $trendingSounds = Sound::query()
            ->availableForUsers()
            ->orderByDesc('uses_count')
            ->orderByDesc('base_uses')
            ->limit(10)
            ->get();

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
