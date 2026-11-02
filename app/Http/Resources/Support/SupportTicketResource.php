<?php

namespace App\Http\Resources\Support;

use App\Models\Page\Page;
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
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'page' => ($page = $this->page_id ? ($this->relationLoaded('page') ? $this->page : Page::find($this->page_id)) : null) ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
            ] : null,
            'user' => [
                'id' => (int) $this->user_id,
                'name' => $this->user?->display_name ?: $this->user?->name,
                'handle' => $this->user?->handle,
                'email' => $this->user?->email,
            ],
            'author' => $page ? [
                'id' => (string) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'avatar_url' => $page->logo_url,
                'is_certified' => $page->isCertified(),
            ] : [
                'id' => (string) $this->user_id,
                'name' => $this->user?->display_name ?: $this->user?->name,
                'handle' => $this->user?->handle,
                'avatar_url' => $this->user?->avatar_url,
                'email' => $this->user?->email,
            ],
            'assigned_agent' => $this->assigned_to ? [
                'id' => (int) $this->assigned_to,
                'name' => $this->assignedAgent?->display_name ?: $this->assignedAgent?->name,
                'handle' => $this->assignedAgent?->handle,
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
