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
        if ($conversation->type === ConversationType::Page && $conversation->page_id) {
            /** @var Page|null $page */
            $page = $conversation->relationLoaded('page') ? $conversation->page : Page::find($conversation->page_id);
            if ($page && $page->members()->where('user_id', $user->id)->where('status', 'revoked')->exists()) {
                return false;
            }

            return $page ? $page->canUserAccessInbox($user) : false;
        }

        return $conversation->isMember($user->id);
    }

    /**
     * Détermine si l'utilisateur peut poster un message dans la conversation.
     */
    public function sendMessage(User $user, Conversation $conversation): bool
    {
        if ($conversation->type === ConversationType::Page && $conversation->page_id) {
            /** @var Page|null $page */
            $page = $conversation->relationLoaded('page') ? $conversation->page : Page::find($conversation->page_id);
            // EDGE-02 : Révocation immédiate
            if ($page && $page->members()->where('user_id', $user->id)->where('status', 'revoked')->exists()) {
                return false;
            }

            return $page ? $page->canUserAccessInbox($user) : false;
        }

        return $conversation->isMember($user->id);
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
        return $user->isAdmin() || $conversation->isAdmin($user->id);
    }

    /**
     * Détermine si l'utilisateur est le destinataire autorisé d'une demande de message.
     */
    public function handleRequest(User $user, Conversation $conversation): bool
    {
        // Seul le destinataire membre de la conversation (et non l'initiateur de la demande) peut accepter ou rejeter
        return $conversation->isMember($user->id) && (int) $conversation->created_by_id !== (int) $user->id;
    }
}
