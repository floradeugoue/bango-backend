<?php

namespace App\Services\Page;

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use App\Notifications\Page\PageMemberAcceptedNotification;
use App\Notifications\Page\PageMemberDeclinedNotification;
use App\Notifications\Page\PageMemberInvitedNotification;
use App\Notifications\Page\PageMemberRevokedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PageMemberService
{
    /**
     * Invite un utilisateur à rejoindre l'équipe de la Page.
     */
    public function invite(Page $page, User $inviter, User $invitee, PageRole $role = PageRole::Editor): PageMember
    {
        return DB::transaction(function () use ($page, $inviter, $invitee, $role) {
            /** @var PageMember|null $existing */
            $existing = PageMember::where('page_id', $page->id)
                ->where('user_id', $invitee->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === PageMemberStatus::Accepted) {
                throw ValidationException::withMessages([
                    'user_id' => ['Cet utilisateur est déjà un membre actif de cette page.'],
                ]);
            }

            if ($existing && $existing->status === PageMemberStatus::Pending) {
                throw ValidationException::withMessages([
                    'user_id' => ['Une invitation est déjà en cours d\'attente pour cet utilisateur.'],
                ]);
            }

            $member = $existing ?? new PageMember([
                'page_id' => $page->id,
                'user_id' => $invitee->id,
            ]);

            $member->role = $role;
            $member->status = PageMemberStatus::Pending;
            $member->invited_by_id = $inviter->id;
            $member->invited_at = now();
            $member->accepted_at = null;
            $member->revoked_at = null;
            $member->save();

            // Notification envoyée à l'invité
            $invitee->notify(new PageMemberInvitedNotification($member));

            return $member->fresh(['user', 'page', 'inviter']);
        });
    }

    /**
     * Accepte une invitation de membre.
     */
    public function accept(PageMember $member, User $user): PageMember
    {
        if ((int) $member->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'member' => ['Cette invitation ne vous est pas destinée.'],
            ]);
        }

        if ($member->status !== PageMemberStatus::Pending) {
            throw ValidationException::withMessages([
                'member' => ['Cette invitation n\'est plus en attente de réponse.'],
            ]);
        }

        return DB::transaction(function () use ($member) {
            /** @var PageMember $lockedMember */
            $lockedMember = PageMember::where('id', $member->id)->lockForUpdate()->firstOrFail();
            $lockedMember->status = PageMemberStatus::Accepted;
            $lockedMember->accepted_at = now();
            $lockedMember->save();

            // Notification envoyée au propriétaire
            $lockedMember->page->owner->notify(new PageMemberAcceptedNotification($lockedMember));

            return $lockedMember->fresh(['user', 'page']);
        });
    }

    /**
     * Décline une invitation de membre.
     */
    public function decline(PageMember $member, User $user): PageMember
    {
        if ((int) $member->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'member' => ['Cette invitation ne vous est pas destinée.'],
            ]);
        }

        if ($member->status !== PageMemberStatus::Pending) {
            throw ValidationException::withMessages([
                'member' => ['Cette invitation n\'est plus en attente de réponse.'],
            ]);
        }

        return DB::transaction(function () use ($member) {
            /** @var PageMember $lockedMember */
            $lockedMember = PageMember::where('id', $member->id)->lockForUpdate()->firstOrFail();
            $lockedMember->status = PageMemberStatus::Declined;
            $lockedMember->save();

            // Notification envoyée au propriétaire
            $lockedMember->page->owner->notify(new PageMemberDeclinedNotification($lockedMember));

            return $lockedMember->fresh(['user', 'page']);
        });
    }

    /**
     * Modifie le rôle d'un membre avec protection EDGE-01 du dernier propriétaire.
     */
    public function updateRole(PageMember $member, PageRole $newRole, User $actor): PageMember
    {
        return DB::transaction(function () use ($member, $newRole) {
            /** @var PageMember $lockedMember */
            $lockedMember = PageMember::where('id', $member->id)->lockForUpdate()->firstOrFail();

            // EDGE-01 : Si l'on rétrograde un propriétaire accepté, vérifier qu'il en reste un autre
            if ($lockedMember->role === PageRole::Owner && $lockedMember->status === PageMemberStatus::Accepted && $newRole !== PageRole::Owner) {
                $otherOwnersCount = PageMember::where('page_id', $lockedMember->page_id)
                    ->where('role', PageRole::Owner)
                    ->where('status', PageMemberStatus::Accepted)
                    ->where('id', '!=', $lockedMember->id)
                    ->lockForUpdate()
                    ->count();

                if ($otherOwnersCount === 0) {
                    throw ValidationException::withMessages([
                        'role' => ['Impossible de rétrograder le dernier propriétaire de la Page.'],
                    ]);
                }
            }

            $lockedMember->role = $newRole;
            $lockedMember->save();

            return $lockedMember->fresh(['user', 'page']);
        });
    }

    /**
     * Révoque un membre avec effet immédiat (EDGE-01 & EDGE-02).
     */
    public function revoke(PageMember $member, User $actor): PageMember
    {
        return DB::transaction(function () use ($member) {
            /** @var PageMember $lockedMember */
            $lockedMember = PageMember::where('id', $member->id)->lockForUpdate()->firstOrFail();

            // EDGE-01 : Protection du dernier propriétaire
            if ($lockedMember->role === PageRole::Owner && $lockedMember->status === PageMemberStatus::Accepted) {
                $otherOwnersCount = PageMember::where('page_id', $lockedMember->page_id)
                    ->where('role', PageRole::Owner)
                    ->where('status', PageMemberStatus::Accepted)
                    ->where('id', '!=', $lockedMember->id)
                    ->lockForUpdate()
                    ->count();

                if ($otherOwnersCount === 0) {
                    throw ValidationException::withMessages([
                        'member' => ['Impossible de révoquer le dernier propriétaire de la Page.'],
                    ]);
                }
            }

            $lockedMember->status = PageMemberStatus::Revoked;
            $lockedMember->revoked_at = now();
            $lockedMember->save();

            // EDGE-02 : Notification immédiate au membre révoqué
            $lockedMember->user->notify(new PageMemberRevokedNotification($lockedMember));

            return $lockedMember->fresh(['user', 'page']);
        });
    }

    /**
     * Permet à un membre de quitter l'équipe de la Page de son propre chef (EDGE-01).
     */
    public function leave(PageMember $member, User $user): void
    {
        if ((int) $member->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'member' => ['Action non autorisée.'],
            ]);
        }

        DB::transaction(function () use ($member) {
            /** @var PageMember $lockedMember */
            $lockedMember = PageMember::where('id', $member->id)->lockForUpdate()->firstOrFail();

            // EDGE-01 : Le dernier propriétaire ne peut pas quitter sans successeur
            if ($lockedMember->role === PageRole::Owner && $lockedMember->status === PageMemberStatus::Accepted) {
                $otherOwnersCount = PageMember::where('page_id', $lockedMember->page_id)
                    ->where('role', PageRole::Owner)
                    ->where('status', PageMemberStatus::Accepted)
                    ->where('id', '!=', $lockedMember->id)
                    ->lockForUpdate()
                    ->count();

                if ($otherOwnersCount === 0) {
                    throw ValidationException::withMessages([
                        'member' => ['Le dernier propriétaire ne peut pas quitter la page sans avoir préalablement désigné ou transféré la propriété.'],
                    ]);
                }
            }

            $lockedMember->status = PageMemberStatus::Revoked;
            $lockedMember->revoked_at = now();
            $lockedMember->save();
        });
    }
}
