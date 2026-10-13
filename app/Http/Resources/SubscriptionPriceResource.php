<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'cadence' => $this->cadence,
            'priceAmount' => (float) $this->price_amount,
            'priceXaf' => (int) $this->price_amount,
            'currency' => $this->currency,
            'durationDays' => $this->duration_days,
            'savingsDescription' => $this->savings_description,
            'isActive' => (bool) $this->is_active,
            'effectiveFrom' => $this->effective_from?->toIso8601String(),
            'effectiveUntil' => $this->effective_until?->toIso8601String(),
        ];
    }
}
