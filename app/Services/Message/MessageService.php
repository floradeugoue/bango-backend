<?php

namespace App\Services\Message;

use App\Enums\Message\MessageType;
use App\Events\Message\MessageRead;
use App\Events\Message\MessageSent;
use App\Models\Delegation\Delegation;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\User;
use App\Notifications\Message\NewMessageNotification;
use App\Notifications\Message\NewStoryReplyNotification;
use App\Services\Listing\ListingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MessageService
{
    public function __construct(
        protected ListingService $listingService
    ) {}

    /**
     * Récupération des messages d'une conversation avec pagination.
     */
    public function listThreadMessages(Conversation $conversation, User $user, int $perPage = 30): LengthAwarePaginator
    {
        if (! $conversation->isMember($user->id)) {
            throw new AccessDeniedHttpException('Vous n\'êtes pas membre de cette conversation.');
        }

        return $conversation->messages()
            ->with(['sender:id,name,username,avatar,avatar_url', 'replyTo'])
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);
    }

    /**
     * Envoi d'un message avec gestion de l'idempotence, permissions agent, blocages silencieux et diffusion.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendMessage(Conversation $conversation, User $sender, array $data): Message
    {
        // 1. Vérification de l'appartenance
        if (! $conversation->isMember($sender->id)) {
            throw new AccessDeniedHttpException('Vous n\'êtes pas membre de cette conversation.');
        }

        // 2. Vérification des permissions immobilières de l'agent (MS-20 / AG)
        if ($conversation->listing_id) {
            $listing = $conversation->listing;
            if ($listing && $sender->id !== $listing->owner_id) {
                $isAgent = ($listing->agent_id === $sender->id) ||
                    Delegation::query()->where('listing_id', $listing->id)->where('agent_id', $sender->id)->exists();

                if ($isAgent && ! $this->listingService->canReplyMessages($sender, $listing)) {
                    throw new AccessDeniedHttpException(
                        'Votre mandat ne vous autorise pas à répondre aux messages de cette annonce ou a été révoqué.'
                    );
                }
            }
        }

        // 3. Idempotence via client_message_id (MS / SY)
        if (! empty($data['client_message_id'])) {
            $existing = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('client_message_id', $data['client_message_id'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // 4. Règle du blocage silencieux (MS-21 / EDGE-22)
        $isSilentlyBlocked = false;
        $blockedRecipientIds = [];

        foreach ($conversation->members as $member) {
            if ($member->user_id === $sender->id) {
                continue;
            }

            $recipientUser = $member->user;
            if ($recipientUser && $recipientUser->hasBlocked($sender)) {
                $isSilentlyBlocked = true;
                $blockedRecipientIds[] = $member->user_id;
            }
        }

        // 5. Persistance atomique
        $message = DB::transaction(function () use ($conversation, $sender, $data, $isSilentlyBlocked): Message {
            $type = $data['type'] ?? MessageType::Text;
            if (is_string($type)) {
                $type = MessageType::from($type);
            }

            $metadata = $data['metadata'] ?? [];
            if ($isSilentlyBlocked) {
                $metadata['undelivered'] = true;
            }

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'type' => $type,
                'body' => $data['body'] ?? null,
                'client_message_id' => $data['client_message_id'] ?? null,
                'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'attachment_mime' => $data['attachment_mime'] ?? null,
                'attachment_size' => $data['attachment_size'] ?? null,
                'attachment_duration_ms' => $data['attachment_duration_ms'] ?? null,
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);

            $conversation->update(['last_message_at' => now()]);

            return $message;
        });

        $message->load(['sender', 'replyTo', 'conversation.members.user']);

        // 6. Diffusion temps réel et notifications
        // Si bloqué silencieusement, l'expéditeur voit son message créé (201 OK), mais aucun broadcast ni notification n'est envoyé aux destinataires bloqueurs
        if (! $isSilentlyBlocked) {
            event(new MessageSent($message));

            foreach ($conversation->members as $member) {
                if ($member->user_id === $sender->id || $member->is_muted) {
                    continue;
                }

                $recipient = $member->user;
                if (! $recipient) {
                    continue;
                }

                if ($message->type === MessageType::StoryReply) {
                    $recipient->notify(new NewStoryReplyNotification(
                        $message,
                        $message->metadata['story_preview_card'] ?? []
                    ));
                } else {
                    $recipient->notify(new NewMessageNotification($message));
                }
            }
        }

        return $message;
    }

    /**
     * Marque la conversation comme lue pour un utilisateur.
     */
    public function markAsRead(Conversation $conversation, User $user, ?string $messageId = null): void
    {
        $member = $conversation->member($user->id);
        if (! $member) {
            throw new AccessDeniedHttpException('Vous n\'êtes pas membre de cette conversation.');
        }

        $member->markAsRead($messageId);

        event(new MessageRead($conversation, $user->id, $messageId));
    }

    /**
     * Rétractation d'un message par son expéditeur.
     */
    public function retractMessage(Conversation $conversation, Message $message, User $actor): Message
    {
        if ($message->conversation_id !== $conversation->id) {
            throw new AccessDeniedHttpException('Message non trouvé dans cette conversation.');
        }

        if ($message->sender_id !== $actor->id) {
            throw new AccessDeniedHttpException('Seul l\'auteur du message peut le rétracter.');
        }

        $message->retract();

        return $message;
    }
}
