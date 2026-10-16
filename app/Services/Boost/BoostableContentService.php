<?php

namespace App\Services\Boost;

use App\Enums\Reel\ReelStatus;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class BoostableContentService
{
    /**
     * Calcule le score composite d'engagement d'un contenu.
     */
    public function computeEngagementScore(Post|Reel $content): int
    {
        if ($content instanceof Reel) {
            return (int) $content->views_count
                + ((int) $content->likes_count * 2)
                + ((int) $content->comments_count * 3)
                + ((int) $content->shares_count * 5);
        }

        return ((int) $content->likes_count * 2)
            + ((int) $content->comments_count * 3)
            + ((int) $content->shares_count * 5);
    }

    /**
     * Récupère la liste paginée des contenus éligibles au sponsoring (SQ et RL)
     * avec calcul statistique du percentile de surperformance relative.
     */
    public function getBoostableContents(
        User $user,
        ?Page $page = null,
        int $pageNumber = 1,
        int $perPage = 15
    ): LengthAwarePaginator {
        // Contrôle d'éligibilité centralisé via Gate boost.start
        Gate::authorize('boost.start', [null, $page]);

        // 1. Récupération des publications Square et Reels éligibles
        $postsQuery = Post::query()
            ->active()
            ->whereNull('deleted_at')
            ->with(['media', 'campaigns' => fn ($q) => $q->whereIn('status', ['running', 'paused', 'pending_approval'])]);

        $reelsQuery = Reel::query()
            ->where('status', ReelStatus::Ready)
            ->whereNull('deleted_at')
            ->with(['campaigns' => fn ($q) => $q->whereIn('status', ['running', 'paused', 'pending_approval'])]);

        if ($page !== null) {
            $postsQuery->where('page_id', $page->id);
            $reelsQuery->where('page_id', $page->id);
        } else {
            $postsQuery->where('user_id', $user->id)->whereNull('page_id');
            $reelsQuery->where('user_id', $user->id)->whereNull('page_id');
        }

        $allPosts = $postsQuery->get();
        $allReels = $reelsQuery->get();

        // 2. Fusion et calcul des scores d'engagement de la population de référence
        $unifiedItems = collect();

        foreach ($allPosts as $post) {
            $score = $this->computeEngagementScore($post);
            $firstMedia = $post->media?->first();

            $unifiedItems->push([
                'id' => (string) $post->id,
                'content_id' => (int) $post->id,
                'type' => 'post',
                'title' => mb_substr((string) ($post->body ?? 'Publication Square'), 0, 80),
                'caption' => (string) ($post->body ?? ''),
                'thumbnail_url' => $firstMedia?->media_url ?? null,
                'created_at' => $post->created_at?->toIso8601String(),
                'is_active_boost' => $post->campaigns->isNotEmpty(),
                'active_campaign_id' => $post->campaigns->first()?->id,
                'metrics' => [
                    'views' => 0,
                    'likes' => (int) $post->likes_count,
                    'comments' => (int) $post->comments_count,
                    'shares' => (int) $post->shares_count,
                    'engagement_score' => $score,
                ],
                '_raw_score' => $score,
                '_created_at' => $post->created_at,
            ]);
        }

        foreach ($allReels as $reel) {
            $score = $this->computeEngagementScore($reel);

            $unifiedItems->push([
                'id' => (string) $reel->id,
                'content_id' => (string) $reel->id,
                'type' => 'reel',
                'title' => mb_substr((string) ($reel->caption ?? 'Reel'), 0, 80),
                'caption' => (string) ($reel->caption ?? ''),
                'thumbnail_url' => $reel->thumbnail_url ?? null,
                'created_at' => $reel->created_at?->toIso8601String(),
                'is_active_boost' => $reel->campaigns->isNotEmpty(),
                'active_campaign_id' => $reel->campaigns->first()?->id,
                'metrics' => [
                    'views' => (int) $reel->views_count,
                    'likes' => (int) $reel->likes_count,
                    'comments' => (int) $reel->comments_count,
                    'shares' => (int) $reel->shares_count,
                    'engagement_score' => $score,
                ],
                '_raw_score' => $score,
                '_created_at' => $reel->created_at,
            ]);
        }

        // 3. Calcul du score de surperformance relative (Percentile)
        $totalContents = $unifiedItems->count();
        $allScores = $unifiedItems->pluck('_raw_score')->all();
        $maxScore = ! empty($allScores) ? max($allScores) : 0;
        $hasSufficientData = ($totalContents >= 3 && $maxScore > 0);

        $calculatedItems = $unifiedItems->map(function ($item) use ($totalContents, $allScores, $hasSufficientData) {
            if (! $hasSufficientData) {
                $item['outperformance'] = [
                    'percentile' => null,
                    'label' => 'Données insuffisantes pour établir une comparaison',
                    'has_sufficient_data' => false,
                ];

                return $item;
            }

            $myScore = $item['_raw_score'];
            $strictlyLower = 0;
            $equals = 0;

            foreach ($allScores as $otherScore) {
                if ($otherScore < $myScore) {
                    $strictlyLower++;
                } elseif ($otherScore === $myScore) {
                    $equals++;
                }
            }

            // Exclure l'élément lui-même du décompte des égalités
            $equalsExcludingSelf = max(0, $equals - 1);
            $denominator = max(1, $totalContents - 1);

            $percentile = (int) round((($strictlyLower + (0.5 * $equalsExcludingSelf)) / $denominator) * 100);
            $percentile = min(max($percentile, 1), 99);

            $item['outperformance'] = [
                'percentile' => $percentile,
                'label' => "Performe mieux que {$percentile} % de tes contenus",
                'has_sufficient_data' => true,
            ];

            return $item;
        });

        // 4. Tri par surperformance puis récence
        $sorted = $calculatedItems->sort(function ($a, $b) {
            if ($a['_raw_score'] !== $b['_raw_score']) {
                return $b['_raw_score'] <=> $a['_raw_score'];
            }

            return $b['_created_at'] <=> $a['_created_at'];
        })->values();

        // 5. Nettoyage des clés temporaires
        $finalItems = $sorted->map(function ($item) {
            unset($item['_raw_score'], $item['_created_at']);

            return $item;
        });

        // 6. Pagination manuelle de la collection fusionnée
        $perPage = min(max($perPage, 1), 50);
        $offset = ($pageNumber - 1) * $perPage;
        $slice = $finalItems->slice($offset, $perPage)->values();

        return new ConcretePaginator(
            $slice,
            $finalItems->count(),
            $perPage,
            $pageNumber,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }
}
