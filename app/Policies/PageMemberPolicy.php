<?php

namespace App\Policies;

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;

class PageMemberPolicy
{
    /**
     * Détermine si l'utilisateur peut voir la liste des membres.
     */
    public function viewAny(User $user, Page $page): bool
    {
        return $user->isAdmin() || $page->hasMember($user);
    }

    /**
     * Détermine si l'utilisateur peut inviter un nouveau membre.
     */
    public function invite(User $user, Page $page): bool
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
     * Détermine si l'utilisateur peut changer le rôle d'un membre.
     */
    public function updateRole(User $user, Page $page, PageMember $member): bool
    {
        if ($page->isSuspended() && ! $user->isAdmin()) {
            return false;
        }

        $isOwner = $user->isAdmin() || $page->memberRole($user) === PageRole::Owner;
        if (! $isOwner) {
            return false;
        }

        // EDGE-01 : Protection du dernier propriétaire
        if ($member->role === PageRole::Owner && $member->status === PageMemberStatus::Accepted) {
            $otherOwnersCount = $page->members()
                ->where('role', PageRole::Owner)
                ->where('status', PageMemberStatus::Accepted)
                ->where('id', '!=', $member->id)
                ->count();

            if ($otherOwnersCount === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Détermine si l'utilisateur peut révoquer un membre.
     */
    public function revoke(User $user, Page $page, PageMember $member): bool
    {
        if ($page->isSuspended() && ! $user->isAdmin()) {
            return false;
        }

        $isOwner = $user->isAdmin() || $page->memberRole($user) === PageRole::Owner;
        if (! $isOwner) {
            return false;
        }

        // EDGE-01 : Impossible de révoquer le dernier propriétaire
        if ($member->role === PageRole::Owner && $member->status === PageMemberStatus::Accepted) {
            $otherOwnersCount = $page->members()
                ->where('role', PageRole::Owner)
                ->where('status', PageMemberStatus::Accepted)
                ->where('id', '!=', $member->id)
                ->count();

            if ($otherOwnersCount === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Détermine si l'utilisateur peut quitter la page de sa propre initiative.
     */
    public function leave(User $user, Page $page, PageMember $member): bool
    {
        if ((int) $user->id !== (int) $member->user_id) {
            return false;
        }

        // EDGE-01 : Le dernier propriétaire ne peut pas quitter sans successeur
        if ($member->role === PageRole::Owner && $member->status === PageMemberStatus::Accepted) {
            $otherOwnersCount = $page->members()
                ->where('role', PageRole::Owner)
                ->where('status', PageMemberStatus::Accepted)
                ->where('id', '!=', $member->id)
                ->count();

            if ($otherOwnersCount === 0) {
                return false;
            }
        }

        return true;
    }
}
