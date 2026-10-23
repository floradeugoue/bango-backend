<?php

namespace App\Http\Resources\Profile;

use App\Models\Profile\UserMute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserMute
 */
class MutedUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mutedUser = $this->mutedUser;

        return [
            'id' => (string) ($mutedUser?->id ?? $this->muted_user_id),
            'name' => $mutedUser?->name ?? '',
            'display_name' => $mutedUser?->display_name ?? $mutedUser?->name ?? '',
            'handle' => $mutedUser?->handle,
            'avatar_url' => $mutedUser?->avatar_url,
            'badge' => $mutedUser?->badge ?? 'none',
            'is_verified' => (bool) ($mutedUser?->is_verified ?? false),
            'city' => $mutedUser?->city,
            'neighbourhood' => $mutedUser?->neighbourhood,
            'expires_at' => $this->expires_at?->toISOString(),
            'muted_at' => $this->created_at?->toISOString(),
        ];
    }
}
