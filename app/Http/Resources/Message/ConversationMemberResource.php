<?php

namespace App\Http\Resources\Message;

use App\Models\Message\ConversationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversationMember
 */
class ConversationMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'role' => $this->role->value,
            'is_muted' => (bool) $this->is_muted,
            'last_read_message_id' => $this->last_read_message_id,
            'last_read_at' => $this->last_read_at?->toIso8601String(),
            'joined_at' => $this->joined_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', function () {
                if (! $this->user) {
                    return [
                        'id' => null,
                        'name' => 'Compte supprimé',
                        'username' => 'deleted_user',
                        'avatar_url' => null,
                    ];
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'username' => $this->user->username ?? $this->user->handle,
                    'avatar_url' => $this->user->avatar_url ?? $this->user->avatar,
                    'is_verified' => (bool) $this->user->is_verified,
                    'account_type' => $this->user->account_type?->value ?? 'personal',
                ];
            }),
        ];
    }
}
