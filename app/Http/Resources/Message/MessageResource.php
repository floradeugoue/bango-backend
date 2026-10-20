<?php

namespace App\Http\Resources\Message;

use App\Models\Message\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'conversation_id' => (string) $this->conversation_id,
            'thread_id' => (string) $this->conversation_id,
            'sender_id' => $this->sender_id,
            'sender' => $this->whenLoaded('sender', function () {
                if (! $this->sender) {
                    return [
                        'id' => null,
                        'name' => 'Compte supprimé',
                        'username' => 'deleted_user',
                        'avatar_url' => null,
                    ];
                }

                return [
                    'id' => $this->sender->id,
                    'name' => $this->sender->name,
                    'username' => $this->sender->username ?? $this->sender->handle,
                    'avatar_url' => $this->sender->avatar_url ?? $this->sender->avatar,
                    'is_verified' => (bool) $this->sender->is_verified,
                ];
            }),
            'type' => $this->type->value,
            'kind' => $this->type->value,
            'body' => $this->is_retracted ? null : $this->body,
            'client_message_id' => $this->client_message_id,
            'reply_to_message_id' => $this->reply_to_message_id,
            'reply_to' => $this->whenLoaded('replyTo', function () {
                return $this->replyTo ? new MessageResource($this->replyTo) : null;
            }),
            'attachment_url' => $this->is_retracted ? null : $this->attachment_url,
            'attachment_mime' => $this->is_retracted ? null : $this->attachment_mime,
            'attachment_size' => $this->is_retracted ? null : $this->attachment_size,
            'attachment_duration_ms' => $this->is_retracted ? null : $this->attachment_duration_ms,
            'metadata' => $this->metadata ?? [],
            'reactions' => $this->metadata['reactions'] ?? [],
            'is_retracted' => (bool) $this->is_retracted,
            'retracted_at' => $this->retracted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
