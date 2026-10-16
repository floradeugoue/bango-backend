<?php

namespace App\Http\Resources\Message;

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
                    ->count();
            } elseif ($member) {
                $unreadCount = $this->messages()
                    ->where('sender_id', '!=', $currentUserId)
                    ->count();
            }
        }

        return [
            'id' => (string) $this->id,
            'thread_id' => (string) $this->id,
            'type' => $this->type->value,
            'title' => $this->title,
            'avatar_url' => $this->avatar_url,
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
            'latest_message' => $this->relationLoaded('latestMessage') && $this->latestMessage
                ? new MessageResource($this->latestMessage)
                : null,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'unread_count' => $unreadCount,
            'status' => $this->status ?? 'active',
            'is_request' => $this->status === 'pending',
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
