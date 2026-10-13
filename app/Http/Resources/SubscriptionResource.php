<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'badge' => $this->badge_type,
            'badgeType' => $this->badge_type,
            'state' => $this->state,
            'cadence' => $this->cadence,
            'amount' => (float) $this->amount,
            'priceXaf' => (int) $this->amount,
            'currency' => $this->currency,
            'currentPeriodStart' => $this->current_period_start?->toIso8601String(),
            'currentPeriodEnd' => $this->current_period_end?->toIso8601String(),
            'graceUntil' => $this->grace_until?->toIso8601String(),
            'failedAttempts' => (int) $this->failed_attempts,
            'autoRenew' => (bool) $this->auto_renew,
            'nextRenewal' => $this->next_renewal?->toIso8601String(),
            'canceledAt' => $this->canceled_at?->toIso8601String(),
            'cancelReason' => $this->cancel_reason,
            'isActive' => $this->isActive(),
            'isInGrace' => $this->isInGrace(),
            'isUsable' => $this->isUsable(),
            'isExpired' => $this->isExpired(),
            'product' => new SubscriptionProductResource($this->whenLoaded('product')),
            'price' => new SubscriptionPriceResource($this->whenLoaded('price')),
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'handle' => $this->user->handle,
                    'name' => $this->user->display_name ?? $this->user->name,
                    'email' => $this->user->email,
                    'phone' => $this->user->phone,
                    'isVerified' => (bool) $this->user->is_verified,
                ];
            }),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
