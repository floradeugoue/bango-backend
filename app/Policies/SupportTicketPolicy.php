<?php

namespace App\Policies;

use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketAttachment;
use App\Models\User;

class SupportTicketPolicy
{
    /**
     * Détermine si l'utilisateur peut lister ses propres tickets.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Détermine si l'utilisateur peut afficher les détails d'un ticket.
     */
    public function view(User $user, SupportTicket $ticket): bool
    {
        if ($user->isSupportAgent()) {
            return true;
        }

        return (int) $ticket->user_id === (int) $user->id;
    }

    /**
     * Détermine si l'utilisateur peut créer un ticket.
     */
    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }

    /**
     * Détermine si l'utilisateur peut poster un message dans le ticket.
     */
    public function reply(User $user, SupportTicket $ticket): bool
    {
        if ($user->isSupportAgent()) {
            return true;
        }

        if ((int) $ticket->user_id !== (int) $user->id) {
            return false;
        }

        return $ticket->canBeRepliedTo();
    }

    /**
     * Détermine si l'utilisateur peut modifier le statut du ticket.
     */
    public function updateStatus(User $user, SupportTicket $ticket): bool
    {
        return $user->isSupportAgent();
    }

    /**
     * Détermine si l'utilisateur peut assigner le ticket à un agent.
     */
    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $user->isSupportAgent();
    }

    /**
     * Détermine si l'utilisateur peut ajouter une note interne.
     */
    public function addInternalNote(User $user, SupportTicket $ticket): bool
    {
        return $user->isSupportAgent();
    }

    /**
     * Détermine si l'utilisateur peut télécharger une pièce jointe.
     */
    public function viewAttachment(User $user, SupportTicketAttachment $attachment): bool
    {
        if ($user->isSupportAgent()) {
            return true;
        }

        $ticket = $attachment->relationLoaded('ticket') ? $attachment->ticket : $attachment->ticket()->first();
        if (! $ticket || (int) $ticket->user_id !== (int) $user->id) {
            return false;
        }

        $message = $attachment->relationLoaded('message') ? $attachment->message : $attachment->message()->first();
        if ($message && $message->is_internal) {
            return false;
        }

        return true;
    }
}
