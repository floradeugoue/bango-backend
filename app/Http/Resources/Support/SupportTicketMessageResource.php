<?php

namespace App\Http\Resources\Support;

use App\Models\Support\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicketMessage
 */
class SupportTicketMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAgent = $user?->isSupportAgent() ?? false;

        return [
            'id' => (int) $this->id,
            'support_ticket_id' => (int) $this->support_ticket_id,
            'sender' => [
                'id' => $this->sender_id,
                'name' => $this->sender?->name ?? 'Support BANGO',
            ],
            'sender_type' => $this->sender_type->value,
            'message' => $this->message,
            'is_internal' => $isAgent ? (bool) $this->is_internal : false,
            'attachments' => SupportTicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
