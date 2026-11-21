<?php

namespace App\Services\Profile;

use App\Models\User;
use App\Notifications\Profile\ConfirmNeighbourhoodNotification;
use Illuminate\Database\Eloquent\Builder;

class NeighbourhoodConfirmationInvitationService
{
    /**
     * Requête des comptes actifs dont le quartier est déclaré mais pas encore vérifié par la position.
     */
    public function unverifiedAccountsQuery(): Builder
    {
        return User::query()
            ->where('status', 'active')
            ->whereNull('neighbourhood_verified_at')
            ->whereNotNull('neighbourhood')
            ->where('neighbourhood', '!=', '');
    }

    /**
     * Invite de manière idempotente les comptes existants non vérifiés à confirmer leur quartier.
     * L'envoi de la notification déclenche également la diffusion push via SendPushOnNotificationSent.
     *
     * @return array{checked: int, sent: int, skipped: int}
     */
    public function inviteUnverifiedAccounts(
        bool $dryRun = false,
        bool $force = false,
        int $limit = 0
    ): array {
        $stats = [
            'checked' => 0,
            'sent' => 0,
            'skipped' => 0,
        ];

        $query = $this->unverifiedAccountsQuery()->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $query->chunkById(200, function ($users) use (&$stats, $dryRun, $force, $limit): bool {
            foreach ($users as $user) {
                if ($limit > 0 && $stats['checked'] >= $limit) {
                    return false;
                }

                $stats['checked']++;

                $alreadyInvited = ! $force && $user->notifications()
                    ->where('type', ConfirmNeighbourhoodNotification::class)
                    ->exists();

                if ($alreadyInvited) {
                    $stats['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $stats['sent']++;

                    continue;
                }

                $user->notify(new ConfirmNeighbourhoodNotification(
                    $user->cityName(),
                    $user->neighbourhoodName()
                ));
                $stats['sent']++;
            }

            return true;
        });

        return $stats;
    }

    /**
     * Marque comme lues les invitations de confirmation de quartier dès que le compte est vérifié.
     */
    public function markInvitationsRead(User $user): void
    {
        $user->unreadNotifications()
            ->where('type', ConfirmNeighbourhoodNotification::class)
            ->update(['read_at' => now()]);
    }
}
