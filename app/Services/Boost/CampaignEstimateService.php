<?php

namespace App\Services\Boost;

use App\Models\Subscription\SubscriptionProduct;

class CampaignEstimateService
{
    public const DEFAULT_CPM_XAF = 2180.00; // Tarif catalogue standard pour 1 000 impressions

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
    protected function resolveCpmPrice(): float
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
     * Estime le bassin d'audience ciblé selon les critères démographiques et géographiques.
     *
     * @param  array<string, mixed>  $targeting
     */
    protected function estimateAudiencePool(array $targeting): int
    {
        $neighbourhoods = $targeting['neighbourhoods'] ?? [];
        $spread = max(1, count($neighbourhoods));
        $ageMin = (int) ($targeting['age_min'] ?? 18);
        $ageMax = (int) ($targeting['age_max'] ?? 65);
        $years = max(1, $ageMax - $ageMin);
        $interests = $targeting['interests'] ?? [];
        $topics = max(1, count($interests));
        $radiusKm = (int) ($targeting['radius_km'] ?? 10);

        // Modèle cohérent avec le contrat de l'application cliente
        return (int) round(($spread * $years * $topics * 190) + ($radiusKm * 240));
    }
}
