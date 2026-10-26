<?php

namespace App\Services\Boost;

use App\Models\Geo\City;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;

class CampaignEstimateService
{
    public const DEFAULT_CPM_XAF = 2180.00; // Tarif catalogue standard pour 1 000 impressions

    public function __construct(
        protected ?AdDeliveryService $adDeliveryService = null
    ) {
        $this->adDeliveryService = $adDeliveryService ?? app(AdDeliveryService::class);
    }

    /**
     * Calcule l'estimation de portée prévisionnelle pour une campagne.
     *
     * @param  array{
     *     budget: float|int|string,
     *     currency?: string,
     *     duration_days?: int,
     *     targeting?: array{
     *         city?: string|null,
     *         neighbourhoods?: array<string>|null,
     *         radius_km?: int|null,
     *         age_min?: int|null,
     *         age_max?: int|null,
     *         interests?: array<string>|null
     *     }|null
     * }  $params
     * @return array<string, mixed>
     */
    public function estimate(array $params): array
    {
        $budget = (float) ($params['budget'] ?? 0);
        $currency = strtoupper($params['currency'] ?? 'XAF');
        $durationDays = max(1, (int) ($params['duration_days'] ?? 7));
        $targeting = $params['targeting'] ?? [];

        // Conversion en équivalent XAF (XOF/XAF parité 1:1)
        $budgetXaf = $this->convertToXaf($budget, $currency);

        $cpm = $this->resolveCpmPrice();

        if ($budgetXaf <= 0) {
            $low = 0;
            $high = 0;
            $effectiveCpm = 0;
        } else {
            $low = (int) round(($budgetXaf / $cpm) * 1000);
            $high = (int) round($low * 1.29);
            $effectiveCpm = $low > 0 ? (int) round(($budget / $low) * 1000) : (int) $cpm;
        }

        $dailyBudget = round($budget / $durationDays, 2);
        $dailyReachLow = (int) round($low / $durationDays);
        $dailyReachHigh = (int) round($high / $durationDays);

        $estimatedAudiencePool = $this->estimateAudiencePool($targeting);

        return [
            'budget' => $budget,
            'currency' => $currency,
            'duration_days' => $durationDays,
            'daily_budget' => $dailyBudget,
            'reach' => [
                'low' => $low,
                'high' => $high,
                'daily_low' => $dailyReachLow,
                'daily_high' => $dailyReachHigh,
            ],
            'cpm' => $effectiveCpm,
            'estimated_audience_pool' => $estimatedAudiencePool,
            'is_guaranteed' => false,
            'disclaimer' => 'Cette estimation est purement indicative et ne constitue en aucun cas une garantie contractuelle de performance. La portée réelle dépend de l\'inventaire publicitaire disponible, des enchères concurrentes et des interactions des utilisateurs.',
            'assumptions' => [
                'cpm_baseline' => sprintf('%s XAF pour 1 000 impressions ciblées', number_format($cpm, 2, ',', ' ')),
                'high_bound_factor' => '+29% selon la viralité du contenu et l\'engagement initial',
                'pacing' => 'Diffusion répartie uniformément sur la durée sélectionnée',
            ],
        ];
    }

    /**
     * Résout le coût CPM de référence configuré dans le catalogue BANGO.
     */
    public function resolveCpmPrice(): float
    {
        $product = SubscriptionProduct::where('code', 'listing_boost')->first();
        if ($product) {
            $price = $product->prices()->where('cadence', 'cpm')->where('is_active', true)->first();
            if ($price) {
                return (float) $price->price_amount;
            }
        }

        return self::DEFAULT_CPM_XAF;
    }

    /**
     * Convertit le montant dans la devise de référence XAF.
     */
    protected function convertToXaf(float $amount, string $currency): float
    {
        return match ($currency) {
            'XAF', 'XOF' => $amount,
            'EUR' => $amount * 655.957,
            'USD' => $amount * 600.0,
            default => $amount,
        };
    }

    /**
     * Estime le bassin d'audience ciblé selon les critères démographiques et géographiques réels.
     * Compte les comptes utilisateurs qui passent les filtres de l'étape 1 de diffusion.
     *
     * @param  array<string, mixed>  $targeting
     */
    protected function estimateAudiencePool(array $targeting): int
    {
        $query = User::query()
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'suspended');
            });

        // 1. Filtrage géographique (ville et rayon éventuel)
        if (! empty($targeting['city'])) {
            $targetCity = (string) $targeting['city'];
            $radiusKm = ! empty($targeting['radius_km']) ? (float) $targeting['radius_km'] : null;

            if ($radiusKm !== null && $radiusKm > 0) {
                // Trouver les villes éligibles dans le rayon
                $matchingCities = [$targetCity];
                $allCities = City::query()->whereNotNull('lat')->whereNotNull('lng')->get();
                $centerCity = $allCities->first(fn ($c) => strcasecmp($c->name, $targetCity) === 0);

                if ($centerCity) {
                    foreach ($allCities as $candidateCity) {
                        if (strcasecmp($candidateCity->name, $targetCity) === 0) {
                            continue;
                        }
                        $distance = $this->adDeliveryService->calculateCityDistance($targetCity, $candidateCity->name);
                        if ($distance !== null && $distance <= $radiusKm) {
                            $matchingCities[] = $candidateCity->name;
                        }
                    }
                }

                $query->whereIn('city', $matchingCities);
            } else {
                $query->where('city', $targetCity);
            }
        }

        // 2. Quartiers ciblés (si renseignés, l'utilisateur doit être dans l'un de ces quartiers)
        if (! empty($targeting['neighbourhoods']) && is_array($targeting['neighbourhoods'])) {
            $query->whereIn('neighbourhood', $targeting['neighbourhoods']);
        }

        // 3. Tranche d'âge (calculée d'après birth_date)
        if (! empty($targeting['age_min']) || ! empty($targeting['age_max'])) {
            $query->whereNotNull('birth_date');

            if (! empty($targeting['age_min'])) {
                $maxBirthDate = now()->subYears((int) $targeting['age_min'])->endOfDay();
                $query->where('birth_date', '<=', $maxBirthDate);
            }

            if (! empty($targeting['age_max'])) {
                $minBirthDate = now()->subYears((int) $targeting['age_max'] + 1)->startOfDay();
                $query->where('birth_date', '>=', $minBirthDate);
            }
        }

        // 4. Centres d'intérêt : au moins un en commun
        if (! empty($targeting['interests']) && is_array($targeting['interests'])) {
            $query->whereHas('interests', function ($q) use ($targeting) {
                $q->whereIn('interests.id', $targeting['interests']);
            });
        }

        // 5. Consentement : exclusion si refus de personnalisation publicitaire alors que du profilage est requis
        $hasProfiling = ! empty($targeting['age_min'])
            || ! empty($targeting['age_max'])
            || ! empty($targeting['interests'])
            || ! empty($targeting['neighbourhoods']);

        if ($hasProfiling) {
            $query->where(function ($q) {
                $q->whereNull('privacy_settings')
                    ->orWhereRaw("JSON_EXTRACT(privacy_settings, '$.ads_personalization') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(privacy_settings, '$.ads_personalization') = true")
                    ->orWhereRaw("JSON_EXTRACT(privacy_settings, '$.ads_personalization') = 'true'");
            });
        }

        return $query->count();
    }
}
