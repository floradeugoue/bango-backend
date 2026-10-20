<?php

namespace App\Services\Listing;

use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListingSearchService
{
    /**
     * Recherche paginée des annonces pour le feed immobilier (IM-00a).
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchListings(array $filters, int $perPage = 15, ?User $viewer = null): LengthAwarePaginator
    {
        $query = $this->buildBaseQuery($filters, $viewer);

        // Tri stable : annonces sponsorisées en priorité, puis par date de publication décroissante
        return $query->with(['owner', 'agent', 'photos', 'videos', 'saves'])
            ->orderByDesc('sponsored')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Compteur dynamique des annonces correspondant aux filtres actifs.
     * Garantit une stricte égalité entre le compteur et les résultats du feed.
     *
     * @param  array<string, mixed>  $filters
     */
    public function countListings(array $filters, ?User $viewer = null): int
    {
        return $this->buildBaseQuery($filters, $viewer)->count();
    }

    /**
     * Recherche géographique sur carte avec clustering adaptatif selon le niveau de zoom (IM-02).
     *
     * @param  array{north?: float, south?: float, east?: float, west?: float}  $bounds
     * @param  array<string, mixed>  $filters
     * @return array{
     *     pins: array<array{id: string, lat: float, lng: float, label: string, variant: string, count?: int, listing?: mixed}>,
     *     total: int
     * }
     */
    public function mapSearch(array $bounds, array $filters, int $zoom = 13, ?User $viewer = null): array
    {
        $mergedFilters = array_merge($filters, $bounds);
        $query = $this->buildBaseQuery($mergedFilters, $viewer);

        // Seules les annonces avec coordonnées valides peuvent figurer sur la carte
        $query->whereNotNull('lat')->whereNotNull('lng');

        /** @var Collection<int, Listing> $listings */
        $listings = $query->with(['owner', 'photos', 'videos', 'saves'])->get();
        $total = $listings->count();

        // Clustering basé sur une grille géographique si le zoom est éloigné et que la densité est suffisante
        $clusterGridSize = $this->calculateGridSize($zoom);
        $clusters = [];
        $pins = [];

        if ($zoom < 14 && $total > 4) {
            foreach ($listings as $listing) {
                $gridX = (int) floor(($listing->lng + 180) / $clusterGridSize);
                $gridY = (int) floor(($listing->lat + 90) / $clusterGridSize);
                $cellKey = "{$gridX}_{$gridY}";

                $clusters[$cellKey][] = $listing;
            }

            foreach ($clusters as $cellKey => $cellListings) {
                if (count($cellListings) === 1) {
                    $pins[] = $this->formatListingPin($cellListings[0], $viewer);
                } else {
                    $avgLat = array_sum(array_map(fn (Listing $l) => $l->lat, $cellListings)) / count($cellListings);
                    $avgLng = array_sum(array_map(fn (Listing $l) => $l->lng, $cellListings)) / count($cellListings);

                    $pins[] = [
                        'id' => "cluster_{$cellKey}",
                        'lat' => round($avgLat, 6),
                        'lng' => round($avgLng, 6),
                        'label' => (string) count($cellListings),
                        'variant' => 'cluster',
                        'count' => count($cellListings),
                    ];
                }
            }
        } else {
            foreach ($listings as $listing) {
                $pins[] = $this->formatListingPin($listing, $viewer);
            }
        }

        return [
            'pins' => $pins,
            'clusters' => array_values(array_filter($pins, fn (array $p) => ($p['variant'] ?? '') === 'cluster')),
            'total' => $total,
        ];
    }

    /**
     * Construit la requête de base respectant la visibilité publique et les blocages.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function buildBaseQuery(array $filters, ?User $viewer = null): Builder
    {
        $query = Listing::query()->publiclyVisible();

        // Exclusion des utilisateurs bloqués ou bloqueurs si un utilisateur est connecté
        if ($viewer) {
            $blockedUserIds = $viewer->blockedUsers()->pluck('blocked_id')
                ->merge($viewer->blockedByUsers()->pluck('blocker_id'))
                ->unique();

            if ($blockedUserIds->isNotEmpty()) {
                $query->whereNotIn('owner_id', $blockedUserIds);
            }
        }

        // Application des filtres métier (mode, ville, quartier, prix, chambres, équipements, etc.)
        $query->filter($filters);

        return $query;
    }

    /**
     * Formate une pastille (pin) individuelle pour la carte.
     *
     * @return array{
     *     id: string,
     *     lat: float,
     *     lng: float,
     *     label: string,
     *     variant: string,
     *     listing: array<string, mixed>
     * }
     */
    protected function formatListingPin(Listing $listing, ?User $viewer = null): array
    {
        $isSaved = $viewer ? $listing->isSavedBy($viewer) : false;
        $priceFormatted = $this->formatPriceBadge((float) $listing->price, $listing->currency ?? 'XAF');

        return [
            'id' => $listing->id,
            'lat' => (float) $listing->lat,
            'lng' => (float) $listing->lng,
            'label' => $priceFormatted,
            'variant' => $isSaved ? 'saved' : 'plain',
            'listing' => [
                'id' => $listing->id,
                'title' => $listing->title,
                'price' => (float) $listing->price,
                'currency' => $listing->currency,
                'city' => $listing->city,
                'neighbourhood' => $listing->neighbourhood,
                'status' => $listing->status->value,
                'photo_url' => $listing->mainPhoto()?->url,
            ],
        ];
    }

    /**
     * Calcule la taille d'une cellule de grille en degrés selon le niveau de zoom.
     */
    protected function calculateGridSize(int $zoom): float
    {
        return match (true) {
            $zoom <= 8 => 0.5,
            $zoom <= 10 => 0.15,
            $zoom <= 12 => 0.05,
            default => 0.015,
        };
    }

    /**
     * Formate un libellé compact de prix pour les pastilles de carte (ex: "150 k").
     */
    protected function formatPriceBadge(float $price, string $currency): string
    {
        if ($price >= 1000000) {
            $m = round($price / 1000000, 1);

            return "{$m} M";
        }

        if ($price >= 1000) {
            $k = round($price / 1000);

            return "{$k} k";
        }

        return (string) round($price);
    }
}
