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
            'prices' => SubscriptionPriceResource::collection($this->whenLoaded('activePrices', function () {
                return $this->activePrices;
            }, function () {
                return $this->relationLoaded('prices') ? $this->prices : $this->activePrices;
            })),
        ];
    }
}
