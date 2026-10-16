<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageThreadRequest;
use App\Http\Resources\Message\ConversationResource;
use App\Models\Message\Conversation;
use App\Services\Message\MessageService;
use App\Services\Message\MessageThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MessageThreadController extends Controller
{
    public function __construct(
        protected MessageThreadService $threadService,
        protected MessageService $messageService
    ) {}

    /**
     * Liste des conversations de l'utilisateur.
     *
     * GET /api/messages/threads
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $threads = $this->threadService->listUserThreads(
            user: $request->user(),
            filters: $request->only(['type', 'search']),
            perPage: (int) $request->input('per_page', 20)
        );

        return ConversationResource::collection($threads);
    }

    /**
     * Crée ou récupère une conversation directe (avec ou sans contexte annonce).
     *
     * POST /api/messages/threads
     */
    public function store(StoreMessageThreadRequest $request): JsonResponse
    {
        $conversation = $this->threadService->getOrCreateDirectConversation(
            user: $request->user(),
            peerId: (int) $request->validated('peer_id'),
            listingId: $request->validated('listing_id'),
            metadata: $request->validated('metadata')
        );

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Affiche une conversation spécifique.
     *
     * GET /api/messages/threads/{id}
     */
    public function show(string $id, Request $request): ConversationResource
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()
            ->with([
                'members.user',
                'listing',
                'latestMessage.sender',
            ])
            ->findOrFail($id);

        Gate::authorize('view', $thread);

        return new ConversationResource($thread);
    }

    /**
     * Marque les messages de la conversation comme lus.
     *
     * POST /api/messages/threads/{id}/read
     */
    public function markAsRead(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);

        Gate::authorize('view', $thread);

        $this->messageService->markAsRead(
            conversation: $thread,
            user: $request->user(),
            messageId: $request->input('message_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversation marquée comme lue.',
        ]);
    }
}
