<?php

namespace App\Http\Resources\Support;

use App\Models\Support\SupportTicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupportTicketAttachment
 */
class SupportTicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'file_size' => (int) $this->file_size,
            'download_url' => route('api.support.tickets.attachments.download', [
                'ticketId' => $this->support_ticket_id,
                'attachmentId' => $this->id,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
