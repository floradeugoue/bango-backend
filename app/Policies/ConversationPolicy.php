<?php

namespace App\Policies;

use App\Enums\Message\ConversationType;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\User;

class ConversationPolicy
{
    /**
     * Détermine si l'utilisateur peut afficher les détails et messages d'une conversation.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        if ($conversation->type === ConversationType::Page || $conversation->page_id) {
            /** @var Page|null $page */
            $page = $conversation->relationLoaded('page') ? $conversation->page : Page::find($conversation->page_id);
            if ($page && $page->members()->where('user_id', $user->id)->where('status', 'revoked')->exists()) {
                return false;
            }

            if ($page && $page->canUserAccessInbox($user)) {
                return true;
            }

            return (int) $conversation->created_by_id === (int) $user->id || $conversation->isMember($user->id);
        }

        return $conversation->isMember($user->id);
    }

    /**
     * Détermine si l'utilisateur peut poster un message dans la conversation.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        if ($conversation->type === ConversationType::Page || $conversation->page_id) {
            /** @var Page|null $page */
            $page = $conversation->relationLoaded('page') ? $conversation->page : Page::find($conversation->page_id);
            // EDGE-02 : Révocation immédiate
            if ($page && $page->members()->where('user_id', $user->id)->where('status', 'revoked')->exists()) {
                return false;
            }

            if ($conversation->is_read_only) {
                return false;
            }

            if ($page && $page->canUserAccessInbox($user)) {
                return true;
            }

            return (int) $conversation->created_by_id === (int) $user->id || $conversation->isMember($user->id);
        }

        if ($conversation->is_read_only) {
            return false;
        }

        if ($conversation->type === ConversationType::Group && $conversation->admins_only && ! $conversation->isAdmin($user->id)) {
            return false;
        }

        if ($conversation->type === ConversationType::Direct) {
            $peerMember = $conversation->members()->where('user_id', '!=', $user->id)->first();
            if ($peerMember) {
                $peer = $peerMember->relationLoaded('user') ? $peerMember->user : User::find($peerMember->user_id);
                if ($peer && ! $peer->canBeMessagedBy($user)) {
                    $hasPeerMessaged = $conversation->messages()->where('sender_id', $peer->id)->exists();
                    if (! $hasPeerMessaged) {
                        return false;
                    }
                }
            }
        }

        return $conversation->isMember($user->id);
    }

    /**
     * Détermine si l'utilisateur peut modifier les réglages du groupe.
     */
    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->type === ConversationType::Group &&
            ($conversation->isAdmin($user->id) || (int) $conversation->created_by_id === (int) $user->id);
    }

    /**
     * Détermine si l'utilisateur peut ajouter des membres dans le groupe.
     */
    public function addMembers(User $user, Conversation $conversation): bool
    {
        return $conversation->type === ConversationType::Group && $conversation->isAdmin($user->id);
    }

    /**
     * Détermine si l'utilisateur peut retirer un membre d'un groupe.
     */
    public function removeMember(User $user, Conversation $conversation, int $targetUserId): bool
    {
        return $conversation->type === ConversationType::Group &&
            ($conversation->isAdmin($user->id) || (int) $user->id === $targetUserId);
    }

    /**
     * Suppression logique d'une conversation.
     */
    public function delete(User $user, Conversation $conversation): bool
    {
        return $user->isAdmin() || $conversation->isAdmin($user->id) || (int) $conversation->created_by_id === (int) $user->id;
    }

    /**
     * Détermine si l'utilisateur est le destinataire autorisé d'une demande de message.
     */
    public function handleRequest(User $user, Conversation $conversation): bool
    {
        if ($conversation->type === ConversationType::Page || $conversation->page_id) {
            /** @var Page|null $page */
            $page = $conversation->relationLoaded('page') ? $conversation->page : Page::find($conversation->page_id);
            if ($page && $page->canUserAccessInbox($user)) {
                return (int) $conversation->created_by_id !== (int) $user->id;
            }
        }

        // Seul le destinataire membre de la conversation (et non l'initiateur de la demande) peut accepter ou rejeter
        return $conversation->isMember($user->id) && (int) $conversation->created_by_id !== (int) $user->id;
    }
}
