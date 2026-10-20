<?php

namespace App\Http\Resources\Support;

use App\Models\Support\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'category' => [
                'value' => $this->category->value,
                'label' => $this->category->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'priority' => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
            ],
            'user' => [
                'id' => (int) $this->user_id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ],
            'assigned_agent' => $this->assigned_to ? [
                'id' => (int) $this->assigned_to,
                'name' => $this->assignedAgent?->name,
            ] : null,
            'messages_count' => $this->whenCounted('messages'),
            'messages' => SupportTicketMessageResource::collection($this->whenLoaded('messages')),
            'rating' => $this->rating,
            'rating_comment' => $this->rating_comment,
            'rated_at' => $this->rated_at?->toIso8601String(),
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
