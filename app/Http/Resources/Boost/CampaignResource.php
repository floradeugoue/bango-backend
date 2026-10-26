<?php

namespace App\Http\Resources\Boost;

use App\Models\Boost\Campaign;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Campaign
 */
class CampaignResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shortKind = match ($this->boostable_type) {
            Post::class, 'post', 'posts' => 'post',
            Reel::class, 'reel', 'reels' => 'reel',
            Listing::class, 'listing', 'listings' => 'listing',
            default => 'unknown',
        };

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'page' => $this->page ? [
                'id' => (int) $this->page->id,
                'name' => $this->page->name,
                'handle' => $this->page->handle,
                'logo_url' => $this->page->logo_url,
                'is_certified' => $this->page->isCertified(),
            ] : null,
            'sponsor_type' => $this->page_id ? 'page' : 'user',
            'sponsored_by' => $this->page ? "Sponsorisé par {$this->page->name}" : ($this->metadata['sponsored_by'] ?? ($this->user?->name ?? 'Sponsorisé')),
            'kind' => $shortKind,
            'boostable_type' => $this->boostable_type,
            'boostable_id' => $this->boostable_id,
            'content_label' => $this->metadata['content_label'] ?? null,
            'objective' => [
                'value' => $this->objective->value,
                'label' => $this->objective->label(),
            ],
            'budget' => (float) $this->budget_amount,
            'currency' => $this->budget_currency,
            'duration_days' => $this->duration_days,
            'start_at' => $this->start_at?->toIso8601String(),
            'end_at' => $this->end_at?->toIso8601String(),
            'targeting' => $this->targeting,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'thumbnail_url' => $this->resolveThumbnailUrl(),
            'stopped_reason' => $this->stopped_reason,
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'spent' => (float) $this->spent_amount,
            'unspent' => max(0.0, (float) $this->budget_amount - (float) $this->spent_amount),
            'impressions_count' => $this->impressions_count,
            'clicks_count' => $this->clicks_count,
            'reach_count' => $this->reach_count,
            'audience_breakdown' => $this->resolveAudienceBreakdown(),
            'results' => $this->resolveResultsSummary(),
            'daily' => $this->resolveDailySeries(),
            'metrics' => CampaignMetricResource::collection($this->whenLoaded('metrics')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Résout l'URL de la vignette du contenu sponsorisé (photo ou image de la vidéo).
     */
    protected function resolveThumbnailUrl(): ?string
    {
        // 1. Si une URL est stockée dans les métadonnées de campagne
        if (! empty($this->metadata['thumbnail_url'])) {
            return (string) $this->metadata['thumbnail_url'];
        }

        // 2. Résolution via le boostable
        $content = $this->relationLoaded('boostable')
            ? $this->boostable
            : $this->boostable()->first();

        if (! $content) {
            return null;
        }

        if ($content instanceof Reel) {
            return $content->thumbnail_url ?? null;
        }

        if ($content instanceof Post) {
            $media = $content->relationLoaded('media') ? $content->media : $content->media()->get();
            $firstMedia = $media->first();

            return $firstMedia?->media_url ?? null;
        }

        if ($content instanceof Listing) {
            $photos = $content->relationLoaded('photos') ? $content->photos : $content->photos()->get();
            $firstPhoto = $photos->first();

            return $firstPhoto?->thumbnail_url ?? $firstPhoto?->url ?? null;
        }

        return null;
    }

    /**
     * Calcule la répartition de l'audience atteinte par quartier et par tranche d'âge
     * basée sur les impressions réelles enregistrées (campaign_impressions).
     *
     * @return array{neighbourhoods: array<int, array{name: string, share: float}>, age_bands: array<int, array{min: int, max: ?int, share: float}>}
     */
    protected function resolveAudienceBreakdown(): array
    {
        $impressions = $this->relationLoaded('impressions')
            ? $this->impressions
            : $this->impressions()->with('user')->get();

        // Récupération des spectateurs uniques (reach réel)
        $reachedUsers = $impressions
            ->whereNotNull('user')
            ->pluck('user')
            ->unique('id')
            ->values();

        $totalUsers = $reachedUsers->count();

        if ($totalUsers === 0) {
            return [
                'neighbourhoods' => [],
                'age_bands' => [],
            ];
        }

        // 1. Répartition par quartier
        $neighbourhoodCounts = [];
        foreach ($reachedUsers as $u) {
            $rawName = trim((string) ($u->neighbourhood ?? ''));
            $name = $rawName !== '' ? $rawName : 'Non renseigné';
            $neighbourhoodCounts[$name] = ($neighbourhoodCounts[$name] ?? 0) + 1;
        }

        arsort($neighbourhoodCounts);

        $neighbourhoods = [];
        foreach ($neighbourhoodCounts as $name => $count) {
            $neighbourhoods[] = [
                'name' => (string) $name,
                'share' => round($count / $totalUsers, 4),
            ];
        }

        // 2. Tranches d'âge standard
        $definitions = [
            ['min' => 13, 'max' => 17],
            ['min' => 18, 'max' => 24],
            ['min' => 25, 'max' => 34],
            ['min' => 35, 'max' => 44],
            ['min' => 45, 'max' => 54],
            ['min' => 55, 'max' => 64],
            ['min' => 65, 'max' => null],
        ];

        $bandCounts = array_fill(0, count($definitions), 0);
        $withAgeCount = 0;

        foreach ($reachedUsers as $u) {
            if (! $u->birth_date) {
                continue;
            }

            $age = $u->birth_date->age;
            $withAgeCount++;

            foreach ($definitions as $idx => $def) {
                $matchesMin = $age >= $def['min'];
                $matchesMax = $def['max'] === null || $age <= $def['max'];

                if ($matchesMin && $matchesMax) {
                    $bandCounts[$idx]++;
                    break;
                }
            }
        }

        $ageBands = [];
        $denominator = $withAgeCount > 0 ? $withAgeCount : $totalUsers;

        foreach ($definitions as $idx => $def) {
            $count = $bandCounts[$idx];
            if ($count > 0) {
                $ageBands[] = [
                    'min' => $def['min'],
                    'max' => $def['max'],
                    'share' => round($count / $denominator, 4),
                ];
            }
        }

        return [
            'neighbourhoods' => $neighbourhoods,
            'age_bands' => $ageBands,
        ];
    }

    /**
     * Résumé des résultats attribués à la campagne : followers, messages, profile_visits, engagements.
     *
     * @return array{followers: int, messages: int, profile_visits: int, engagements: int}
     */
    protected function resolveResultsSummary(): array
    {
        $resultsCollection = $this->relationLoaded('results')
            ? $this->results
            : $this->results()->get();

        $followers = $resultsCollection->where('type', 'follow')->count();
        $messages = $resultsCollection->where('type', 'message')->count();
        $profileVisits = $resultsCollection->where('type', 'profile_visit')->count();
        $engagements = $resultsCollection->where('type', 'engagement')->count();

        // Si aucun engagement spécifique n'est tracé mais qu'il y a des clics, on inclut au moins les clics comme proxy
        if ($engagements === 0 && (int) $this->clicks_count > 0) {
            $engagements = (int) $this->clicks_count;
        }

        return [
            'followers' => $followers,
            'messages' => $messages,
            'profile_visits' => $profileVisits,
            'engagements' => $engagements,
        ];
    }

    /**
     * Série quotidienne pour BO-07 : [{date, reach, impressions, results, spent}].
     *
     * @return array<int, array{date: string, reach: int, impressions: int, results: int, spent: float}>
     */
    protected function resolveDailySeries(): array
    {
        $metrics = $this->relationLoaded('metrics')
            ? $this->metrics
            : $this->metrics()->orderBy('metric_date')->get();

        if ($metrics->isEmpty()) {
            return [];
        }

        // Résultats groupés par date (occurred_at)
        $resultsCollection = $this->relationLoaded('results')
            ? $this->results
            : $this->results()->get();

        $resultsByDate = $resultsCollection
            ->groupBy(fn ($res) => $res->occurred_at ? $res->occurred_at->toDateString() : $res->created_at->toDateString())
            ->map(fn ($group) => $group->count());

        $series = [];
        foreach ($metrics as $metric) {
            $dateString = $metric->metric_date?->toDateString();
            $dailyResultsCount = $resultsByDate->get($dateString, 0);

            // Si c'est un objectif Reach/Engagement et qu'il n'y a pas de résultat direct, clicks peut alimenter results
            if ($dailyResultsCount === 0 && (int) $metric->clicks > 0) {
                $dailyResultsCount = (int) $metric->clicks;
            }

            $series[] = [
                'date' => $dateString,
                'reach' => (int) $metric->reach,
                'impressions' => (int) $metric->impressions,
                'results' => $dailyResultsCount,
                'spent' => (float) $metric->spent,
            ];
        }

        return $series;
    }
}
