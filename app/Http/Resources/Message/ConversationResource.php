<?php

namespace App\Http\Resources\Message;

use App\Enums\Message\ConversationType;
use App\Models\Message\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentUserId = $request->user()?->id;

        // Calcul du nombre de messages non lus pour l'utilisateur courant
        $unreadCount = 0;
        if ($currentUserId) {
            $member = $this->members->firstWhere('user_id', $currentUserId);
            if ($member && $member->last_read_at) {
                $unreadCount = $this->messages()
                    ->where('created_at', '>', $member->last_read_at)
                    ->where('sender_id', '!=', $currentUserId)
                    ->where(function ($q) {
                        $q->whereNull('metadata->undelivered')
                            ->orWhere('metadata->undelivered', false);
                    })
                    ->count();
            } elseif ($member) {
                $unreadCount = $this->messages()
                    ->where('sender_id', '!=', $currentUserId)
                    ->where(function ($q) {
                        $q->whereNull('metadata->undelivered')
                            ->orWhere('metadata->undelivered', false);
                    })
                    ->count();
            }
        }

        // Titre par défaut : pour les conversations directes sans titre explicite, utiliser le nom de l'interlocuteur
        $title = $this->title;
        $isMuted = false;
        $isArchived = false;
        $pinnedAt = null;

        if ($currentUserId) {
            $currentMember = $this->members->firstWhere('user_id', $currentUserId);
            $isMuted = (bool) ($currentMember?->is_muted);
            $pinnedAt = $currentMember?->pinned_at?->toIso8601String();
            $isArchived = in_array($currentUserId, $this->metadata['archived_by'] ?? [], true);

            if (! $title && $this->type === ConversationType::Direct) {
                $otherMember = $this->members->firstWhere('user_id', '!=', $currentUserId);
                $otherUser = $otherMember?->user;
                if ($otherUser) {
                    $title = $otherUser->display_name ?: ($otherUser->name ?: ($otherUser->handle ?: 'Utilisateur'));
                }
            }
        }

        return [
            'id' => (string) $this->id,
            'thread_id' => (string) $this->id,
            'type' => $this->type->value,
            'title' => $title,
            'avatar_url' => $this->avatar_url,
            'is_muted' => $isMuted,
            'is_archived' => $isArchived,
            'pinned_at' => $pinnedAt,
            'is_pinned' => $pinnedAt !== null,
            'listing_id' => $this->listing_id ? (string) $this->listing_id : null,
            'listing' => $this->whenLoaded('listing', function () {
                if (! $this->listing) {
                    return null;
                }

                return [
                    'id' => (string) $this->listing->id,
                    'title' => $this->listing->title,
                    'price' => (float) $this->listing->price,
                    'currency' => $this->listing->currency,
                    'status' => $this->listing->status->value,
                    'mode' => $this->listing->mode->value,
                    'kind' => $this->listing->kind->value,
                    'cover_image' => $this->listing->cover_image_url ?? $this->listing->media?->first()?->url,
                    'owner_id' => $this->listing->owner_id,
                    'agent_id' => $this->listing->agent_id,
                ];
            }),
            'members' => ConversationMemberResource::collection($this->whenLoaded('members')),
            'latest_message' => (function () use ($currentUserId) {
                if (! $this->relationLoaded('latestMessage') || ! $this->latestMessage) {
                    return null;
                }
                $latest = $this->latestMessage;
                $isUndelivered = (bool) ($latest->metadata['undelivered'] ?? false);
                if ($isUndelivered && $currentUserId && $latest->sender_id !== $currentUserId) {
                    // Trouver le dernier message visible pour cet utilisateur
                    $fallback = $this->messages()
                        ->visibleTo($currentUserId)
                        ->with(['sender:id,name,display_name,handle,avatar_url,is_verified,is_certified'])
                        ->orderByDesc('created_at')
                        ->first();

                    return $fallback ? new MessageResource($fallback) : null;
                }

                return new MessageResource($latest);
            })(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'status' => $this->status ?? 'active',
            'is_request' => $this->status === 'pending',
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
