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
            ...$this->resolveDeliveryAndReadStatus($request),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Calcule le statut d'envoi, de distribution et de lecture du message.
     * Trois coches : 'sent' (simple), 'delivered' (double grise), 'read' (double bleue).
     *
     * @return array<string, mixed>
     */
    protected function resolveDeliveryAndReadStatus(Request $request): array
    {
        $conversation = $this->relationLoaded('conversation') ? $this->conversation : null;

        if (! $conversation) {
            return [
                'status' => 'sent',
                'is_delivered' => false,
                'is_read' => false,
                'delivered_at' => null,
                'read_at' => null,
            ];
        }

        // Identifier les destinataires (les autres membres que l'expéditeur)
        $members = $conversation->relationLoaded('members')
            ? $conversation->members
            : $conversation->members()->get();

        $recipientMembers = $members->filter(fn ($m) => $m->user_id !== $this->sender_id);

        if ($recipientMembers->isEmpty()) {
            return [
                'status' => 'sent',
                'is_delivered' => false,
                'is_read' => false,
                'delivered_at' => null,
                'read_at' => null,
            ];
        }

        $createdAt = $this->created_at;

        // Lu si au moins un destinataire a un last_read_at >= created_at
        // (pour conversation directe, c'est l'autre membre ; pour groupe, au moins un membre l'a lu)
        $readMembers = $recipientMembers->filter(fn ($m) => $m->last_read_at && $m->last_read_at->greaterThanOrEqualTo($createdAt));
        $isRead = $readMembers->isNotEmpty();
        $firstReadAt = $readMembers->min('last_read_at');

        // Reçu si au moins un destinataire a un last_delivered_at >= created_at (ou last_read_at)
        $deliveredMembers = $recipientMembers->filter(fn ($m) => (
            ($m->last_delivered_at && $m->last_delivered_at->greaterThanOrEqualTo($createdAt)) ||
            ($m->last_read_at && $m->last_read_at->greaterThanOrEqualTo($createdAt))
        ));
        $isDelivered = $deliveredMembers->isNotEmpty();
        $firstDeliveredAt = $deliveredMembers->min('last_delivered_at') ?? $firstReadAt;

        $status = 'sent';
        if ($isRead) {
            $status = 'read';
        } elseif ($isDelivered) {
            $status = 'delivered';
        }

        return [
            'status' => $status,
            'is_delivered' => $isDelivered,
            'is_read' => $isRead,
            'delivered_at' => $firstDeliveredAt?->toIso8601String(),
            'read_at' => $firstReadAt?->toIso8601String(),
        ];
    }
}
