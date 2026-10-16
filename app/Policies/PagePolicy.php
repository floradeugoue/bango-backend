<?php

namespace App\Policies;

use App\Models\Page\Page;
use App\Models\User;

class PagePolicy
{
    /**
     * Détermine si un utilisateur peut voir la liste publique des pages.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Détermine si un utilisateur peut consulter une page spécifique.
     */
    public function view(?User $user, Page $page): bool
    {
        if ($page->isActive()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $page->hasMember($user);
    }

    /**
     * Détermine si un utilisateur authentifié peut créer une nouvelle Page.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Détermine si l'utilisateur peut modifier les informations, le logo et la couverture.
     */
    public function update(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->isOwner($user);
    }

    /**
     * Détermine si l'utilisateur peut supprimer (soft-delete) la Page.
     */
    public function delete(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $page->isOwner($user);
    }

    /**
     * Détermine si l'utilisateur peut transférer la propriété de la Page (EDGE-01).
     */
    public function transferOwnership(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->isOwner($user);
    }

    /**
     * Détermine si l'utilisateur peut administrer les membres et leurs rôles.
     */
    public function manageMembers(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->isOwner($user);
    }

    /**
     * Détermine si l'utilisateur peut publier des contenus Square ou Reels au nom de la Page.
     */
    public function publishContent(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->canUserPublish($user);
    }

    /**
     * Détermine si l'utilisateur peut consulter et répondre dans la boîte de réception de la Page.
     */
    public function accessInbox(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $page->canUserAccessInbox($user);
    }

    /**
     * Détermine si l'utilisateur peut soumettre un dossier de certification et initier son paiement.
     */
    public function submitCertification(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->isOwner($user);
    }

    /**
     * Détermine si l'utilisateur peut sponsoriser des contenus au nom de la Page (BO).
     */
    public function sponsorContent(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->canUserPublish($user);
    }
}
