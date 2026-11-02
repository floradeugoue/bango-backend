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
            'admins_only' => ['nullable', 'boolean'],
        ]);

        $changes = [];

        if (array_key_exists('title', $validated)) {
            $group->title = $validated['title'];
            $changes[] = 'le nom du groupe';
        }
        if (array_key_exists('avatar_url', $validated)) {
            $group->avatar_url = $validated['avatar_url'];
            $changes[] = 'la photo du groupe';
        }
        if (array_key_exists('description', $validated)) {
            $meta = $group->metadata ?? [];
            $meta['description'] = $validated['description'];
            $group->metadata = $meta;
            $changes[] = 'la description du groupe';
        }
        if (array_key_exists('admins_only', $validated)) {
            $group->admins_only = (bool) $validated['admins_only'];
            $changes[] = $group->admins_only
                ? 'les réglages : seuls les administrateurs peuvent envoyer des messages'
                : 'les réglages : tous les membres peuvent envoyer des messages';
        }
        $group->save();

        if (! empty($changes)) {
            $this->threadService->postSystemMessage(
                $group,
                'Un administrateur a modifié '.implode(', ', $changes)
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Réglages du groupe mis à jour.',
            'group' => new ConversationResource($group->fresh(['members.user'])),
        ]);
    }

    /**
     * Supprime un groupe de discussion (réservé au créateur ou admin).
     *
     * DELETE /api/messages/groups/{id}
     */
    public function destroy(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        $this->threadService->deleteGroup($group, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Groupe supprimé avec succès.',
        ]);
    }

    /**
     * Nomme un membre comme administrateur du groupe.
     *
     * POST /api/messages/groups/{id}/admins/{userId}
     */
    public function makeAdmin(string $id, int $userId, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        $this->threadService->setAdminRole($group, $request->user(), $userId, true);

        return response()->json([
            'success' => true,
            'message' => 'Membre nommé administrateur.',
            'group' => new ConversationResource($group->fresh(['members.user'])),
        ]);
    }

    /**
     * Retire le rôle administrateur d'un membre.
     *
     * DELETE /api/messages/groups/{id}/admins/{userId}
     */
    public function removeAdmin(string $id, int $userId, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        $this->threadService->setAdminRole($group, $request->user(), $userId, false);

        return response()->json([
            'success' => true,
            'message' => 'Rôle administrateur retiré.',
            'group' => new ConversationResource($group->fresh(['members.user'])),
        ]);
    }

    /**
     * Crée ou récupère le lien d'invitation du groupe.
     *
     * POST /api/messages/groups/{id}/invite-link
     */
    public function inviteLink(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        $result = $this->threadService->createInviteLink($group, $request->user());

        return response()->json([
            'url' => $result['url'],
            'token' => $result['token'],
        ]);
    }

    /**
     * Révoque le lien d'invitation du groupe.
     *
     * DELETE /api/messages/groups/{id}/invite-link
     */
    public function revokeInviteLink(string $id, Request $request): JsonResponse
    {
        /** @var Conversation $group */
        $group = Conversation::query()->findOrFail($id);

        $this->threadService->revokeInviteLink($group, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Lien d\'invitation révoqué avec succès.',
        ]);
    }

    /**
     * Rejoindre un groupe via un lien / token d'invitation.
     *
     * POST /api/messages/groups/join/{token}
     */
    public function join(string $token, Request $request): JsonResponse
    {
        $group = $this->threadService->joinGroupByInviteToken($token, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Vous avez rejoint le groupe avec succès.',
            'group' => new ConversationResource($group),
        ]);
    }
}
