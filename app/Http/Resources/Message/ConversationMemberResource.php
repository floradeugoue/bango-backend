<?php

namespace App\Http\Resources\Message;

use App\Enums\Message\ConversationMemberRole;
use App\Models\Message\ConversationMember;
use App\Models\User;
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
        $viewer = $request->user();
        $isSelf = $viewer && (int) $this->user_id === (int) $viewer->id;
        $viewerAllowsReceipts = $viewer ? $viewer->hasReadReceiptsEnabled() : true;

        $memberUser = $this->relationLoaded('user') ? $this->user : User::find($this->user_id);
        $memberAllowsReceipts = $memberUser ? $memberUser->hasReadReceiptsEnabled() : true;

        $canExposeReadReceipts = $isSelf || ($viewerAllowsReceipts && $memberAllowsReceipts);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'role' => $this->role->value,
            'is_admin' => $this->role === ConversationMemberRole::Admin,
            'is_muted' => (bool) $this->is_muted,
            'last_read_message_id' => $canExposeReadReceipts ? $this->last_read_message_id : null,
            'last_read_at' => $canExposeReadReceipts ? $this->last_read_at?->toIso8601String() : null,
            'last_delivered_at' => $this->last_delivered_at?->toIso8601String(),
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

                // Respect du réglage « Statut en ligne » (onlineStatus dans privacy_settings, true par défaut)
                $privacy = $this->user->privacy_settings ?? [];
                $allowsOnlineStatus = (bool) ($privacy['onlineStatus'] ?? true);

                $lastSeen = $this->user->last_seen_at;
                $isOnline = false;
                $renderedLastSeen = null;

                if ($allowsOnlineStatus && $lastSeen) {
                    $isOnline = $lastSeen->diffInSeconds(now()) <= 120;
                    $renderedLastSeen = $lastSeen->toIso8601String();
                }

                return [
                    'id' => $this->user->id,
                    'name' => $this->user->display_name ?: $this->user->name,
                    'handle' => $this->user->handle,
                    'username' => $this->user->username ?? $this->user->handle,
                    'avatar_url' => $this->user->avatar_url ?? $this->user->avatar,
                    'is_verified' => (bool) $this->user->is_verified,
                    'certified' => (bool) ($this->user->is_certified ?? false),
                    'is_certified' => (bool) ($this->user->is_certified ?? false),
                    'account_type' => $this->user->account_type?->value ?? 'personal',
                    'is_online' => $isOnline,
                    'last_seen_at' => $renderedLastSeen,
                ];
            }),
        ];
    }
}
