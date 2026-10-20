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
use Illuminate\Support\Facades\Cache;
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

    /**
     * Indique que l'utilisateur est en train d'écrire.
     *
     * POST /api/messages/threads/{id}/typing
     */
    public function setTyping(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);
        Gate::authorize('view', $thread);

        $user = $request->user();
        Cache::put(
            "typing:{$thread->id}:{$user->id}",
            ['id' => $user->id, 'name' => $user->name ?? $user->handle],
            now()->addSeconds(6)
        );

        return response()->json(['success' => true]);
    }

    /**
     * Récupère la liste des utilisateurs en train d'écrire sur le fil.
     *
     * GET /api/messages/threads/{id}/typing
     */
    public function getTyping(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);
        Gate::authorize('view', $thread);

        $active = [];
        foreach ($thread->members as $member) {
            if ($member->user_id === $request->user()->id) {
                continue;
            }
            $cached = Cache::get("typing:{$thread->id}:{$member->user_id}");
            if ($cached) {
                $active[] = $cached;
            }
        }

        return response()->json(['typing' => $active]);
    }

    /**
     * Mettre la conversation en sourdine.
     *
     * POST /api/messages/threads/{id}/mute
     */
    public function mute(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);
        Gate::authorize('view', $thread);

        $member = $thread->members()->where('user_id', $request->user()->id)->firstOrFail();
        $member->update(['is_muted' => true]);

        return response()->json(['success' => true, 'is_muted' => true]);
    }

    /**
     * Réactiver les notifications de la conversation.
     *
     * POST /api/messages/threads/{id}/unmute
     */
    public function unmute(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);
        Gate::authorize('view', $thread);

        $member = $thread->members()->where('user_id', $request->user()->id)->firstOrFail();
        $member->update(['is_muted' => false]);

        return response()->json(['success' => true, 'is_muted' => false]);
    }

    /**
     * Archiver la conversation pour l'utilisateur.
     *
     * POST /api/messages/threads/{id}/archive
     */
    public function archive(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);
        Gate::authorize('view', $thread);

        $meta = $thread->metadata ?? [];
        $archived = $meta['archived_by'] ?? [];
        if (! in_array($request->user()->id, $archived, true)) {
            $archived[] = $request->user()->id;
        }
        $meta['archived_by'] = $archived;
        $thread->metadata = $meta;
        $thread->save();

        return response()->json(['success' => true, 'is_archived' => true]);
    }

    /**
     * Désarchiver la conversation.
     *
     * POST /api/messages/threads/{id}/unarchive
     */
    public function unarchive(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $thread */
        $thread = Conversation::query()->findOrFail($id);
        Gate::authorize('view', $thread);

        $meta = $thread->metadata ?? [];
        $archived = array_values(array_filter($meta['archived_by'] ?? [], fn ($uid) => $uid !== $request->user()->id));
        $meta['archived_by'] = $archived;
        $thread->metadata = $meta;
        $thread->save();

        return response()->json(['success' => true, 'is_archived' => false]);
    }
}
