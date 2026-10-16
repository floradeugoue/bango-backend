<?php

namespace App\Policies;

use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\User;

class PageCertificationPolicy
{
    /**
     * Détermine si l'utilisateur peut consulter les détails d'un dossier de certification.
     */
    public function view(User $user, PageCertification $certification): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $certification->page->memberRole($user) === PageRole::Owner;
    }

    /**
     * Détermine si l'utilisateur peut soumettre un dossier de certification.
     */
    public function submit(User $user, Page $page): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($page->isSuspended()) {
            return false;
        }

        return $page->memberRole($user) === PageRole::Owner;
    }

    /**
     * Détermine si l'utilisateur peut renvoyer une correction (PG-07 : 2 corrections max).
     */
    public function resubmit(User $user, PageCertification $certification): bool
    {
        if ($certification->page->isSuspended() && ! $user->isAdmin()) {
            return false;
        }

        return $user->isAdmin() || $certification->page->isOwner($user);
    }

    /**
     * Détermine si l'utilisateur peut télécharger les pièces justificatives privées.
     */
    public function downloadDocument(User $user, PageCertification $certification): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $certification->page->memberRole($user) === PageRole::Owner;
    }
}
