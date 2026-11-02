<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'badgeType' => $this->badge_type,
            'targetRole' => $this->target_role,
            'features' => $this->features ?? [],
            'isActive' => (bool) $this->is_active,
            'displayOrder' => (int) $this->display_order,
            'settings' => $this->resolveSettings(),
            'prices' => SubscriptionPriceResource::collection($this->whenLoaded('activePrices', function () {
                return $this->activePrices;
            }, function () {
                return $this->relationLoaded('prices') ? $this->prices : $this->activePrices;
            })),
        ];
    }

    /**
     * Settings spécifiques au produit en XAF.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveSettings(): ?array
    {
        if ($this->code === 'listing_boost') {
            return [
                'min_budget' => 100,
                'max_budget' => 50000,
                'budget_step' => 500,
                'budget_steps' => [2000, 6000, 15000, 30000],
                'durations' => [3, 7, 14, 30],
                'operator_fee' => [
                    'type' => 'percentage_with_min',
                    'rate' => 0.02,
                    'min' => 100,
                ],
            ];
        }

        if ($this->code === 'page_pro') {
            return [
                'free_corrections' => 2,
            ];
        }

        return null;
    }
}
