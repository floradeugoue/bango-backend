<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Resources\Message\ConversationResource;
use App\Models\Message\Conversation;
use App\Services\Message\MessageThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageRequestController extends Controller
{
    public function __construct(
        protected MessageThreadService $threadService
    ) {}

    /**
     * Liste paginée des demandes de messages reçues par l'utilisateur (MS-05).
     *
     * GET /api/messages/requests
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requests = $this->threadService->listUserRequests(
            user: $request->user(),
            perPage: (int) $request->input('per_page', 20)
        );

        return ConversationResource::collection($requests);
    }

    /**
     * Accepte une demande de message (MS-05).
     *
     * POST /api/messages/requests/{id}/accept
     */
    public function accept(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $conversation */
        $conversation = Conversation::withTrashed()->findOrFail($id);

        $accepted = $this->threadService->acceptRequest(
            conversation: $conversation,
            user: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Demande de message acceptée avec succès.',
            'data' => new ConversationResource($accepted),
        ]);
    }

    /**
     * Refuse une demande de message (MS-05).
     *
     * POST /api/messages/requests/{id}/reject
     */
    public function reject(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $conversation */
        $conversation = Conversation::withTrashed()->findOrFail($id);

        $this->threadService->rejectRequest(
            conversation: $conversation,
            user: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Demande de message refusée avec succès.',
        ]);
    }
}
