<?php

namespace App\Listeners\Story;

use App\Enums\Message\MessageType;
use App\Events\Story\StoryReplyCreated;
use App\Models\Message\Message;
use App\Models\User;
use App\Services\Message\MessageService;
use App\Services\Message\MessageThreadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateConversationFromStoryReply implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        protected MessageThreadService $threadService,
        protected MessageService $messageService
    ) {}

    /**
     * Traite l'événement StoryReplyCreated et crée la conversation et le message associés.
     */
    public function handle(StoryReplyCreated $event): void
    {
        $reply = $event->reply;

        // 1. Idempotence : si la réponse a déjà été liée à un message ou traitée
        if (! empty($reply->future_message_id) || $reply->status === 'processed') {
            Log::info("StoryReply {$reply->id} déjà traitée (idempotence).");

            return;
        }

        // Vérifier si un message existe déjà avec cet identifiant client idempotent
        $clientMessageId = 'story_reply_'.$reply->id;
        $existingMessage = Message::query()->where('client_message_id', $clientMessageId)->first();
        if ($existingMessage) {
            $reply->update([
                'future_conversation_id' => (string) $existingMessage->conversation_id,
                'future_message_id' => (string) $existingMessage->id,
                'status' => 'processed',
            ]);

            return;
        }

        // 2. Identification des intervenants
        $respondent = User::query()->find($reply->user_id);
        $author = User::query()->find($reply->story_author_id);

        if (! $respondent || ! $author) {
            Log::warning("Impossible de créer la conversation StoryReply {$reply->id} : utilisateur introuvable.");

            return;
        }

        // 3. Traitement atomique sous transaction
        DB::transaction(function () use ($reply, $respondent, $author, $clientMessageId): void {
            // Re-vérifier sous verrou de ligne pour éviter toute concurrence
            $freshReply = $reply->fresh();
            if (! $freshReply || ! empty($freshReply->future_message_id) || $freshReply->status === 'processed') {
                return;
            }

            // Récupérer ou créer la conversation directe entre le répondant et l'auteur de la story
            $conversation = $this->threadService->getOrCreateDirectConversation(
                user: $respondent,
                peerId: $author->id,
                listingId: null,
                metadata: [
                    'source' => 'story_reply',
                    'story_id' => (string) $freshReply->story_id,
                ]
            );

            // Snapshot immuable de la Story issu des métadonnées déjà figées
            $storySnapshot = $freshReply->metadata ?? [];

            // Corps du message
            $body = $freshReply->text ?: ($freshReply->reaction ?: 'Réponse à votre story');

            // Création du message
            $message = $this->messageService->sendMessage($conversation, $respondent, [
                'type' => MessageType::StoryReply,
                'body' => $body,
                'client_message_id' => $clientMessageId,
                'metadata' => [
                    'story_id' => (string) $freshReply->story_id,
                    'story_slide_id' => $freshReply->story_slide_id ? (string) $freshReply->story_slide_id : null,
                    'reaction' => $freshReply->reaction,
                    'reply_type' => $freshReply->type->value,
                    'story_preview_card' => $storySnapshot,
                ],
            ]);

            // Mise à jour de la réponse Story
            $freshReply->update([
                'future_conversation_id' => (string) $conversation->id,
                'future_message_id' => (string) $message->id,
                'status' => 'processed',
            ]);
        });
    }
}
