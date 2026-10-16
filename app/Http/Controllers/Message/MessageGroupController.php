<?php

namespace App\Http\Controllers\Message;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageGroupRequest;
use App\Http\Resources\Message\ConversationResource;
use App\Models\Message\Conversation;
use App\Services\Message\MessageThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MessageGroupController extends Controller
{
    public function __construct(
        protected MessageThreadService $threadService
    ) {}

    /**
     * Crée une nouvelle conversation de groupe.
     *
     * POST /api/messages/groups
     */
    public function store(StoreMessageGroupRequest $request): JsonResponse
    {
        $group = $this->threadService->createGroupConversation(
            creator: $request->user(),
            title: $request->validated('title'),
            memberIds: $request->validated('members'),
            avatarUrl: $request->validated('avatar_url')
        );

        return (new ConversationResource($group))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Ajoute des membres à un groupe existant.
     *
     * POST /api/messages/groups/{id}/members
     */
    public function addMembers(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'exists:users,id'],
        ]);

        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        Gate::authorize('addMembers', $group);

        $this->threadService->addMembersToGroup(
            conversation: $group,
            actor: $request->user(),
            newMemberIds: $validated['members']
        );

        return response()->json([
            'success' => true,
            'message' => 'Membres ajoutés avec succès.',
            'group' => new ConversationResource($group->fresh(['members.user'])),
        ]);
    }

    /**
     * Retire un membre ou quitte un groupe.
     *
     * DELETE /api/messages/groups/{id}/members/{userId}
     */
    public function removeMember(string $id, int $userId, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        Gate::authorize('removeMember', [$group, $userId]);

        $this->threadService->removeMember(
            conversation: $group,
            actor: $request->user(),
            targetUserId: $userId
        );

        return response()->json([
            'success' => true,
            'message' => 'Membre retiré du groupe.',
        ]);
    }
}
