<?php

namespace App\Http\Resources\Delegation;

use App\Models\Delegation\Delegation;
use App\Services\Delegation\DelegationService;
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
        $agent = $this->agent;
        $hasAgentBadge = false;
        if ($agent) {
            $hasAgentBadge = (bool) (
                ($agent->badge && in_array($agent->badge, ['agent', 'double'], true) && ! $agent->verification_lapsed)
                || app(DelegationService::class)->hasActiveAgentBadge($agent)
            );
        }

        return [
            'id' => $this->id,
            'bailleur_id' => $this->bailleur_id,
            'bailleur_name' => $this->bailleur?->display_name ?: $this->bailleur?->name,
            'agent_id' => $this->agent_id,
            'agent_name' => $agent?->display_name ?: $agent?->name,
            'agent' => $agent ? [
                'id' => $agent->id,
                'display_name' => $agent->display_name ?: $agent->name,
                'handle' => $agent->handle,
                'avatar_url' => $agent->avatar_url,
                'city' => $agent->city,
                'neighbourhood' => $agent->neighbourhood,
                'has_agent_badge' => $hasAgentBadge,
            ] : null,
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
