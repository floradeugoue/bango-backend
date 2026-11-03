<?php

namespace App\Http\Resources\Message;

use App\Enums\Delegation\DelegationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Message\Message;
use App\Models\Page\Page;
use App\Models\User;
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
            'page_id' => $this->page_id ? (int) $this->page_id : null,
            'sender' => ($page = $this->page_id ? ($this->relationLoaded('page') ? $this->page : Page::find($this->page_id)) : null) ? [
                'id' => (string) $page->id,
                'name' => $page->name,
                'username' => $page->handle,
                'handle' => '@'.$page->handle,
                'avatar_url' => $page->logo_url,
                'is_verified' => $page->isCertified(),
                'certified' => $page->isCertified(),
                'is_certified' => $page->isCertified(),
                'badge' => $page->isCertified() ? 'page_certified' : 'none',
            ] : $this->whenLoaded('sender', function () {
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
                    'name' => $this->sender->display_name ?: $this->sender->name,
                    'handle' => $this->sender->handle,
                    'username' => $this->sender->username ?? $this->sender->handle,
                    'avatar_url' => $this->sender->avatar_url ?? $this->sender->avatar,
                    'is_verified' => (bool) $this->sender->is_verified,
                    'certified' => (bool) ($this->sender->is_certified ?? false),
                    'is_certified' => (bool) ($this->sender->is_certified ?? false),
                ];
            }),
            'on_behalf_of' => (function () {
                $conversation = $this->conversation;
                if (! $conversation || ! $conversation->listing_id || ! $this->sender_id) {
                    return null;
                }
                $listing = $conversation->listing;
                if (! $listing || $this->sender_id === $listing->owner_id) {
                    return null;
                }
                $isAgent = ($listing->agent_id === $this->sender_id) ||
                    Delegation::where('listing_id', $listing->id)
                        ->where('agent_id', $this->sender_id)
                        ->where('status', DelegationStatus::Active->value)
                        ->whereNull('revoked_at')
                        ->exists();

                return $isAgent ? [
                    'agent_id' => $this->sender_id,
                    'landlord_id' => $listing->owner_id,
                    'label' => 'Au nom du bailleur',
                ] : null;
            })(),
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
        $viewer = $request->user();
        $viewerAllowsReceipts = $viewer ? $viewer->hasReadReceiptsEnabled() : true;

        // Lu si au moins un destinataire a un last_read_at >= created_at ET que les deux parties autorisent les accusés de lecture
        $readMembers = $recipientMembers->filter(function ($m) use ($createdAt, $viewerAllowsReceipts) {
            if (! $viewerAllowsReceipts) {
                return false;
            }

            if (! $m->last_read_at || ! $m->last_read_at->greaterThanOrEqualTo($createdAt)) {
                return false;
            }

            $recipientUser = $m->relationLoaded('user') ? $m->user : User::find($m->user_id);

            return $recipientUser ? $recipientUser->hasReadReceiptsEnabled() : true;
        });

        $isRead = $readMembers->isNotEmpty();
        $firstReadAt = $readMembers->min('last_read_at');

        // Reçu si au moins un destinataire a un last_delivered_at >= created_at (ou last_read_at)
        $deliveredMembers = $recipientMembers->filter(fn ($m) => (
            ($m->last_delivered_at && $m->last_delivered_at->greaterThanOrEqualTo($createdAt)) ||
            ($m->last_read_at && $m->last_read_at->greaterThanOrEqualTo($createdAt))
        ));
        $isDelivered = $deliveredMembers->isNotEmpty();
        $firstDeliveredAt = $deliveredMembers->min('last_delivered_at') ?? ($isRead ? $firstReadAt : $deliveredMembers->min('last_read_at'));

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
            'read_at' => $isRead ? $firstReadAt?->toIso8601String() : null,
        ];
    }
}
