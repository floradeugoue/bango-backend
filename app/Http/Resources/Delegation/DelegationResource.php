<?php

namespace App\Http\Resources\Delegation;

use App\Models\Delegation\Delegation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Delegation
 */
class DelegationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bailleur_id' => $this->bailleur_id,
            'bailleur_name' => $this->bailleur?->display_name ?: $this->bailleur?->name,
            'agent_id' => $this->agent_id,
            'agent_name' => $this->agent?->display_name ?: $this->agent?->name,
            'listing_id' => $this->listing_id,
            'listing_title' => $this->listing?->title,
            'status' => $this->status->value,
            'capabilities' => $this->capabilities ?? [],
            'invited_at' => $this->invited_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_operational' => $this->isOperational(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
