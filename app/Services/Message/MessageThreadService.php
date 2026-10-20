<?php

namespace App\Services\Message;

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Message\ConversationType;
use App\Events\Message\ConversationCreated;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\User;
use App\Notifications\Message\AddedToGroupNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class MessageThreadService
{
    /**
     * Liste paginée des fils de discussion d'un utilisateur.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listUserThreads(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Conversation::query()
            ->forUser($user->id)
            ->with([
                'members.user' => function ($q): void {
                    $q->select(['id', 'name', 'handle', 'avatar', 'avatar_url', 'is_verified', 'account_type']);
                },
                'latestMessage.sender' => function ($q): void {
                    $q->select(['id', 'name', 'handle']);
                },
                'listing' => function ($q): void {
                    $q->select(['id', 'title', 'price', 'currency', 'status', 'mode', 'kind', 'owner_id', 'agent_id']);
                },
            ]);

        // Filtrage par catégorie
        if (! empty($filters['type'])) {
            $type = strtolower((string) $filters['type']);
            if ($type === 'immo') {
                $query->whereNotNull('listing_id');
            } elseif ($type === 'direct') {
                $query->where('type', ConversationType::Direct)->whereNull('listing_id');
            } elseif ($type === 'group') {
                $query->where('type', ConversationType::Group);
            }
        }

        // Dans la boîte principale : exclure les demandes d'inconnus reçues en attente et les conversations rejetées
        $query->where(function (Builder $q) use ($user): void {
            $q->where(function (Builder $sub) {
                $sub->where('status', 'active')->orWhereNull('status');
            })->orWhere(function (Builder $sub) use ($user) {
                $sub->where('status', 'pending')
                    ->where('created_by_id', $user->id);
            });
        })->where(function (Builder $q) {
            $q->where('status', '!=', 'rejected')->orWhereNull('status');
        });

        // Recherche par titre ou nom d'interlocuteur

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('title', 'like', $search)
                    ->orWhereHas('members.user', function (Builder $userQuery) use ($search): void {
                        $userQuery->where('name', 'like', $search)
                            ->orWhere('handle', 'like', $search);
                    });
            });
        }

        // Tri par date du dernier message ou création
        return $query->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->paginate($perPage);
    }

    /**
     * Crée ou récupère une conversation directe (1:1), potentiellement liée à une annonce.
     */
    public function getOrCreateDirectConversation(
        User $user,
        int $peerId,
        ?string $listingId = null,
        ?array $metadata = null
    ): Conversation {
        if ($user->id === $peerId) {
            throw ValidationException::withMessages([
                'peer_id' => ['Vous ne pouvez pas démarrer une conversation avec vous-même.'],
            ]);
        }

        $peer = User::query()->findOrFail($peerId);

        // Si une annonce est renseignée, vérifier qu'elle existe
        if ($listingId) {
            Listing::query()->findOrFail($listingId);
        }

        return DB::transaction(function () use ($user, $peer, $listingId, $metadata): Conversation {
            // Recherche d'un fil direct existant entre ces 2 utilisateurs
            $existingQuery = Conversation::query()
                ->where('type', ConversationType::Direct)
                ->whereHas('members', function (Builder $q) use ($user): void {
                    $q->where('user_id', $user->id)->whereNull('left_at');
                })
                ->whereHas('members', function (Builder $q) use ($peer): void {
                    $q->where('user_id', $peer->id)->whereNull('left_at');
                });

            if ($listingId) {
                // Priorité au fil spécifique à cette annonce
                $listingThread = (clone $existingQuery)->where('listing_id', $listingId)->first();
                if ($listingThread) {
                    return $listingThread;
                }
            }

            // Si pas de listing ou pas encore de fil avec ce listing, chercher le fil direct existant
            $existing = $existingQuery->first();
            if ($existing) {
                // Si un listing est spécifié et que la conversation n'a pas de listing_id, on peut l'attacher
                if ($listingId && ! $existing->listing_id) {
                    $existing->update(['listing_id' => $listingId]);
                }

                return $existing;
            }

            // Déterminer le statut initial selon les critères d'accès direct BANGO (MS-05)
            // 1. Contexte d'annonce immobilière : prise de contact légitime directe -> active
            // 2. Le destinataire suit déjà l'expéditeur -> contact connu -> active
            // 3. Sinon : contact inconnu -> pending (demande de message)
            $isStranger = ! $listingId && ! $peer->isFollowing($user);
            $initialStatus = $isStranger ? 'pending' : 'active';

            // Création d'une nouvelle conversation directe
            $conversation = Conversation::create([
                'type' => ConversationType::Direct,
                'status' => $initialStatus,
                'listing_id' => $listingId,
                'created_by_id' => $user->id,
                'metadata' => $metadata,
            ]);

            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => ConversationMemberRole::Member,
                'joined_at' => now(),
            ]);

            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $peer->id,
                'role' => ConversationMemberRole::Member,
                'joined_at' => now(),
            ]);

            $conversation->load(['members.user', 'listing']);

            // Diffusion temps réel sauf si l'un a bloqué l'autre
            if (! $peer->hasBlocked($user) && ! $user->hasBlocked($peer)) {
                event(new ConversationCreated($conversation));
            }

            return $conversation;
        });
    }

    /**
     * Crée une conversation de groupe.
     *
     * @param  array<int, int>  $memberIds
     */
    public function createGroupConversation(
        User $creator,
        string $title,
        array $memberIds,
        ?string $avatarUrl = null
    ): Conversation {
        // Exclure le créateur des membres reçus pour éviter les doublons
        $uniqueMemberIds = array_values(array_unique(array_filter(
            $memberIds,
            fn ($id) => (int) $id !== (int) $creator->id
        )));

        if (empty($uniqueMemberIds)) {
            throw ValidationException::withMessages([
                'members' => ['Un groupe doit comporter au moins un autre membre.'],
            ]);
        }

        if (count($uniqueMemberIds) > 100) {
            throw ValidationException::withMessages([
                'members' => ['Le nombre maximal de participants est de 100.'],
            ]);
        }

        return DB::transaction(function () use ($creator, $title, $uniqueMemberIds, $avatarUrl): Conversation {
            $conversation = Conversation::create([
                'type' => ConversationType::Group,
                'title' => $title,
                'avatar_url' => $avatarUrl,
                'created_by_id' => $creator->id,
            ]);

            // Ajouter le créateur comme administrateur
            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $creator->id,
                'role' => ConversationMemberRole::Admin,
                'joined_at' => now(),
            ]);

            // Ajouter les membres autorisés
            foreach ($uniqueMemberIds as $memberId) {
                $memberUser = User::query()->find($memberId);
                if (! $memberUser) {
                    continue;
                }

                // Ne pas ajouter si blocage réciproque
                if ($memberUser->hasBlocked($creator) || $creator->hasBlocked($memberUser)) {
                    continue;
                }

                ConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $memberUser->id,
                    'role' => ConversationMemberRole::Member,
                    'joined_at' => now(),
                ]);

                // Notification d'ajout au groupe
                $memberUser->notify(new AddedToGroupNotification($conversation, $creator));
            }

            $conversation->load(['members.user']);

            event(new ConversationCreated($conversation));

            return $conversation;
        });
    }

    /**
     * Ajoute des membres à un groupe.
     *
     * @param  array<int, int>  $newMemberIds
     */
    public function addMembersToGroup(Conversation $conversation, User $actor, array $newMemberIds): void
    {
        if ($conversation->type !== ConversationType::Group) {
            throw new AccessDeniedHttpException('Cette conversation n\'est pas un groupe.');
        }

        if (! $conversation->isAdmin($actor->id)) {
            throw new AccessDeniedHttpException('Seul un administrateur peut ajouter des membres.');
        }

        DB::transaction(function () use ($conversation, $actor, $newMemberIds): void {
            foreach ($newMemberIds as $memberId) {
                $user = User::query()->find($memberId);
                if (! $user) {
                    continue;
                }

                // Vérifier s'il est déjà membre actif
                $existing = $conversation->members()->where('user_id', $user->id)->first();
                if ($existing) {
                    if ($existing->left_at !== null) {
                        $existing->update(['left_at' => null, 'joined_at' => now()]);
                        $user->notify(new AddedToGroupNotification($conversation, $actor));
                    }

                    continue;
                }

                // Ne pas ajouter si blocage
                if ($user->hasBlocked($actor) || $actor->hasBlocked($user)) {
                    continue;
                }

                ConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'role' => ConversationMemberRole::Member,
                    'joined_at' => now(),
                ]);

                $user->notify(new AddedToGroupNotification($conversation, $actor));
            }
        });
    }

    /**
     * Retrait ou départ d'un membre d'un groupe.
     */
    public function removeMember(Conversation $conversation, User $actor, int $targetUserId): void
    {
        if ($conversation->type !== ConversationType::Group) {
            throw new AccessDeniedHttpException('Cette conversation n\'est pas un groupe.');
        }

        $isSelf = (int) $actor->id === (int) $targetUserId;
        $isAdmin = $conversation->isAdmin($actor->id);

        if (! $isSelf && ! $isAdmin) {
            throw new AccessDeniedHttpException('Vous n\'avez pas les droits pour retirer ce membre.');
        }

        $targetMember = $conversation->member($targetUserId);
        if (! $targetMember || $targetMember->left_at !== null) {
            return;
        }

        DB::transaction(function () use ($conversation, $targetMember, $targetUserId): void {
            $targetMember->update(['left_at' => now()]);

            // MS-17 : Si le dernier administrateur quitte le groupe, promouvoir le membre le plus ancien
            if ($targetMember->role === ConversationMemberRole::Admin) {
                $hasOtherAdmins = $conversation->members()
                    ->where('role', ConversationMemberRole::Admin)
                    ->where('user_id', '!=', $targetUserId)
                    ->whereNull('left_at')
                    ->exists();

                if (! $hasOtherAdmins) {
                    /** @var ConversationMember|null $oldestMember */
                    $oldestMember = $conversation->members()
                        ->where('user_id', '!=', $targetUserId)
                        ->whereNull('left_at')
                        ->orderBy('joined_at')
                        ->first();

                    if ($oldestMember) {
                        $oldestMember->update(['role' => ConversationMemberRole::Admin]);
                    }
                }
            }
        });
    }

    /**
     * Liste paginée des demandes de messages reçues par l'utilisateur (MS-05).
     */
    public function listUserRequests(User $user, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 50);

        return Conversation::query()
            ->requests()
            ->forUser($user->id)
            ->where('created_by_id', '!=', $user->id)
            ->whereHas('creator', function (Builder $q): void {
                $q->where('status', 'active')->whereNull('deleted_at');
            })
            ->whereNotExists(function ($q) use ($user): void {
                $q->select(DB::raw(1))
                    ->from('user_blocks')
                    ->where(function ($sub) use ($user): void {
                        $sub->where('user_blocks.blocker_id', $user->id)
                            ->whereColumn('user_blocks.blocked_id', 'conversations.created_by_id');
                    })
                    ->orWhere(function ($sub) use ($user): void {
                        $sub->whereColumn('user_blocks.blocker_id', 'conversations.created_by_id')
                            ->where('user_blocks.blocked_id', $user->id);
                    });
            })
            ->with([
                'members.user' => function ($q): void {
                    $q->select(['id', 'name', 'handle', 'avatar', 'avatar_url', 'badge', 'is_verified', 'verification_lapsed']);
                },
                'latestMessage.sender' => function ($q): void {
                    $q->select(['id', 'name', 'handle']);
                },
                'listing',
            ])
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->paginate($perPage);
    }

    /**
     * Accepte une demande de message et bascule la conversation en fil normal (MS-05).
     */
    public function acceptRequest(Conversation $conversation, User $user): Conversation
    {
        Gate::authorize('handleRequest', $conversation);

        if ($conversation->status === 'active') {
            return $conversation;
        }

        if ($conversation->status === 'rejected') {
            throw ValidationException::withMessages([
                'request' => ['Cette demande de message a déjà été refusée.'],
            ]);
        }

        DB::transaction(function () use ($conversation): void {
            $conversation->update(['status' => 'active']);
        });

        $conversation->load(['members.user', 'latestMessage.sender', 'listing']);

        return $conversation;
    }

    /**
     * Refuse une demande de message et clôture la conversation (MS-05).
     */
    public function rejectRequest(Conversation $conversation, User $user): Conversation
    {
        Gate::authorize('handleRequest', $conversation);

        if ($conversation->status === 'rejected') {
            return $conversation;
        }

        if ($conversation->status === 'active') {
            throw ValidationException::withMessages([
                'request' => ['Cette conversation a déjà été acceptée.'],
            ]);
        }

        DB::transaction(function () use ($conversation): void {
            $conversation->update(['status' => 'rejected']);
            $conversation->delete();
        });

        return $conversation;
    }
}
