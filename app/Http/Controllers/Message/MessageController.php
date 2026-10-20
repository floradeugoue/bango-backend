<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Http\Resources\Message\MessageResource;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Services\Message\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    public function __construct(
        protected MessageService $messageService
    ) {}

    /**
     * Liste ordonnée et paginée des messages d'une conversation.
     *
     * GET /api/messages/threads/{id}/messages
     */
    public function index(string $threadId, Request $request): AnonymousResourceCollection
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($threadId);

        Gate::authorize('view', $thread);

        $messages = $this->messageService->listThreadMessages(
            conversation: $thread,
            user: $request->user(),
            perPage: (int) $request->input('per_page', 30)
        );

        return MessageResource::collection($messages);
    }

    /**
     * Envoi d'un message dans une conversation.
     *
     * POST /api/messages/threads/{id}/messages
     */
    public function store(string $threadId, StoreMessageRequest $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($threadId);

        Gate::authorize('sendMessage', $thread);

        $message = $this->messageService->sendMessage(
            conversation: $thread,
            sender: $request->user(),
            data: $request->validated()
        );

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rétractation d'un message par son expéditeur.
     *
     * POST /api/messages/threads/{id}/messages/{messageId}/retract
     */
    public function retract(string $threadId, string $messageId, Request $request): MessageResource
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($threadId);
        /** @var Message $message */
        $message = Message::query()->findOrFail($messageId);

        $retracted = $this->messageService->retractMessage(
            conversation: $thread,
            message: $message,
            actor: $request->user()
        );

        return new MessageResource($retracted);
    }

    /**
     * Réagir à un message avec un émoji.
     *
     * POST /api/messages/{id}/reactions
     */
    public function react(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        /** @var Message $message */
        $message = Message::query()->findOrFail($id);
        Gate::authorize('view', $message->conversation);

        $reactions = $message->metadata['reactions'] ?? [];
        $user = $request->user();

        // Enlever la réaction précédente de l'utilisateur s'il y en avait une
        $filtered = array_values(array_filter($reactions, fn ($r) => ($r['user_id'] ?? null) !== $user->id));
        $filtered[] = [
            'user_id' => $user->id,
            'user_name' => $user->name ?? $user->handle,
            'emoji' => $validated['emoji'],
            'created_at' => now()->toIso8601String(),
        ];

        $meta = $message->metadata ?? [];
        $meta['reactions'] = $filtered;
        $message->metadata = $meta;
        $message->save();

        return response()->json([
            'success' => true,
            'reactions' => $filtered,
        ]);
    }

    /**
     * Supprimer sa réaction à un message.
     *
     * DELETE /api/messages/{id}/reactions
     */
    public function removeReaction(string $id, Request $request): JsonResponse
    {
        /** @var Message $message */
        $message = Message::query()->findOrFail($id);
        Gate::authorize('view', $message->conversation);

        $reactions = $message->metadata['reactions'] ?? [];
        $user = $request->user();

        $filtered = array_values(array_filter($reactions, fn ($r) => ($r['user_id'] ?? null) !== $user->id));

        $meta = $message->metadata ?? [];
        $meta['reactions'] = $filtered;
        $message->metadata = $meta;
        $message->save();

        return response()->json([
            'success' => true,
            'reactions' => $filtered,
        ]);
    }
}
