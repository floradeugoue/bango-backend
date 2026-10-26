<?php

namespace App\Http\Resources\Agent;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AgentDirectoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name ?? $this->name,
            'handle' => $this->handle,
            'avatar_url' => $this->avatar_url,
            'bio' => $this->bio,
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'badge' => $this->badge ?? 'agent',
            'is_verified' => (bool) $this->is_verified,
            'is_active_30d' => (bool) $this->isActiveLast30Days(),
            'has_activity_30d' => (bool) $this->isActiveLast30Days(),
            'active_mandates_count' => (int) ($this->active_delegations_as_agent_count ?? $this->activeDelegationsAsAgent()->count()),
            'managed_listings_count' => (int) ($this->managed_listings_count ?? $this->managedListingsCount()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
