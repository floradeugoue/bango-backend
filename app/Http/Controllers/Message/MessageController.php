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
}
