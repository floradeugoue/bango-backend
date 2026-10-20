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

    /**
     * Mettre à jour les réglages du groupe (titre, avatar, description).
     *
     * PUT /api/messages/groups/{id}
     */
    public function update(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        Gate::authorize('update', $group);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if (array_key_exists('title', $validated)) {
            $group->title = $validated['title'];
        }
        if (array_key_exists('avatar_url', $validated)) {
            $group->avatar_url = $validated['avatar_url'];
        }
        if (array_key_exists('description', $validated)) {
            $meta = $group->metadata ?? [];
            $meta['description'] = $validated['description'];
            $group->metadata = $meta;
        }
        $group->save();

        return response()->json([
            'success' => true,
            'message' => 'Réglages du groupe mis à jour.',
            'group' => new ConversationResource($group->fresh(['members.user'])),
        ]);
    }
}
