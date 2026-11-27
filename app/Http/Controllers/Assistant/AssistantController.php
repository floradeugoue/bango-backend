<?php

namespace App\Http\Controllers\Assistant;

use App\Enums\Message\MessageType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Message\ConversationResource;
use App\Models\User;
use App\Services\Message\MessageThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(
        protected MessageThreadService $threadService
    ) {}

    /**
     * Récupère ou initialise la conversation synchronisée avec l'Assistant Bango.
     *
     * GET /api/assistant/thread
     * GET /api/messages/assistant
     */
    public function thread(Request $request): JsonResponse
    {
        $user = $request->user();
        $assistant = User::getAssistant();

        $conversation = $this->threadService->getOrCreateDirectConversation(
            user: $user,
            peerId: $assistant->id,
            metadata: ['is_assistant' => true, 'channel' => 'system_assistant']
        );

        // Si la conversation est neuve et n'a pas encore de messages, créer le message d'accueil initial
        if ($conversation->messages()->count() === 0) {
            $displayName = $user->display_name ?: ($user->name ?: '');
            $greeting = $displayName ? "Bonjour {$displayName} ! " : 'Bonjour ! ';

            $conversation->messages()->create([
                'sender_id' => $assistant->id,
                'body' => "{$greeting}Je suis l'Assistant officiel BANGO. Je suis là pour vous accompagner dans votre navigation, répondre à vos questions et vous faire découvrir les meilleures fonctionnalités de l'application !",
                'type' => MessageType::Text,
                'metadata' => [
                    'automated' => true,
                    'system_assistant' => true,
                ],
            ]);
            $conversation->touch();
        }

        $conversation->load(['members.user', 'messages' => fn ($q) => $q->latest()->limit(30)]);

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(200);
    }
}
