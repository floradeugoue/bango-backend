<?php

namespace App\Services\Listing;

use App\Enums\Listing\ListingMode;

class ListingPricingService
{
    public const MAX_LISTING_MONTHS = 36;

    /**
     * Calcule la ventilation des frais d'entrée et les montants récurrents
     * en conformité stricte avec la règle métier CR-45 du frontend BANGO.
     *
     * @param  array{
     *     mode: string|ListingMode,
     *     price: float|int,
     *     deposit_months?: int,
     *     advance_months?: int,
     *     visit_fee?: float|int,
     *     charges?: float|int
     * }  $input
     * @return array{
     *     costs: array<array{id: string, label: string, amount: float, months?: int, perMonth?: bool}>,
     *     upfront: float,
     *     monthly: ?float
     * }
     */
    public function calculatePricing(array $input): array
    {
        $mode = $input['mode'] instanceof ListingMode ? $input['mode']->value : (string) $input['mode'];
        $price = round((float) ($input['price'] ?? 0), 2);
        $depositMonths = max(0, min(self::MAX_LISTING_MONTHS, (int) ($input['deposit_months'] ?? 0)));
        $advanceMonths = max(0, min(self::MAX_LISTING_MONTHS, (int) ($input['advance_months'] ?? 0)));
        $visitFee = round((float) ($input['visit_fee'] ?? 0), 2);
        $charges = round((float) ($input['charges'] ?? 0), 2);

        $costs = [];

        if ($mode === 'sale') {
            $costs[] = [
                'id' => 'purchase',
                'label' => 'Prix de vente',
                'amount' => $price,
            ];
            if ($visitFee > 0) {
                $costs[] = [
                    'id' => 'visit',
                    'label' => 'Frais de réservation de visite',
                    'amount' => $visitFee,
                ];
            }

            $upfront = $price + $visitFee;
            $monthly = null;
        } else {
            // Location ou Colocation
            if ($depositMonths > 0) {
                $costs[] = [
                    'id' => 'deposit',
                    'label' => 'Caution',
                    'amount' => round($price * $depositMonths, 2),
                    'months' => $depositMonths,
                ];
            }

            if ($advanceMonths > 0) {
                $costs[] = [
                    'id' => 'advance',
                    'label' => 'Loyer d\'avance',
                    'amount' => round($price * $advanceMonths, 2),
                    'months' => $advanceMonths,
                ];
            }

            if ($visitFee > 0) {
                $costs[] = [
                    'id' => 'visit',
                    'label' => 'Frais de réservation de visite',
                    'amount' => $visitFee,
                ];
            }

            if ($charges > 0) {
                $costs[] = [
                    'id' => 'charges',
                    'label' => 'Charges d\'immeuble mensuelles',
                    'amount' => $charges,
                    'perMonth' => true,
                ];
            }

            // Upfront (frais à prévoir à l'entrée) exclut strictement les charges mensuelles
            $upfront = round(($price * ($depositMonths + $advanceMonths)) + $visitFee, 2);
            $monthly = round($price + $charges, 2);
        }

        return [
            'costs' => $costs,
            'upfront' => $upfront,
            'monthly' => $monthly,
        ];
    }
}
