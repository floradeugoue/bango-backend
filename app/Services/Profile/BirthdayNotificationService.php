<?php

namespace App\Services\Profile;

use App\Enums\Message\ConversationType;
use App\Models\Message\Conversation;
use App\Models\Profile\BirthdayNotificationLog;
use App\Models\User;
use App\Notifications\Profile\BirthdayFriendNotification;
use App\Notifications\Profile\BirthdaySelfNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class BirthdayNotificationService
{
    /**
     * Traite les anniversaires du jour et envoie les notifications nécessaires.
     *
     * @return array{self_sent: int, friends_sent: int, skipped: int}
     */
    public function notifyBirthdays(?CarbonInterface $referenceDate = null, ?int $targetHour = 9, bool $force = false): array
    {
        $selfSent = 0;
        $friendsSent = 0;
        $skipped = 0;

        // Récupération des utilisateurs éligibles ayant une date de naissance
        $candidates = User::query()
            ->whereNotNull('birth_date')
            ->whereNull('scheduled_for_deletion_at')
            ->where('status', '!=', 'deleted')
            ->where('status', '!=', 'suspended')
            ->where(function ($q): void {
                $q->whereNull('suspended_until')->orWhere('suspended_until', '<=', now());
            })
            ->get();

        foreach ($candidates as $user) {
            if ($user->isSuspended() || $user->trashed()) {
                $skipped++;

                continue;
            }

            $timezone = $user->resolveTimezone();
            $localNow = $referenceDate
                ? Carbon::parse($referenceDate, $timezone)
                : now()->setTimezone($timezone);

            $year = (int) $localNow->year;

            // 1. Vérification de l'heure cible (9h par défaut dans le fuseau du compte)
            if (! $force && (int) $localNow->hour !== $targetHour) {
                $skipped++;

                continue;
            }

            // 2. Vérification que la date courante correspond bien à l'anniversaire de l'utilisateur
            if (! $user->isBirthdayOnDate($localNow)) {
                $skipped++;

                continue;
            }

            // 3. Notification personnelle (birthday_self) — une seule fois par an
            $alreadySelfNotified = BirthdayNotificationLog::query()
                ->where('user_id', $user->id)
                ->where('recipient_id', $user->id)
                ->where('year', $year)
                ->where('type', 'self')
                ->exists();

            if (! $alreadySelfNotified) {
                try {
                    $user->notify(new BirthdaySelfNotification($user));
                    BirthdayNotificationLog::create([
                        'user_id' => $user->id,
                        'recipient_id' => $user->id,
                        'year' => $year,
                        'type' => 'self',
                    ]);
                    $selfSent++;
                } catch (\Throwable $e) {
                    Log::error("Échec de l'envoi notification anniversaire perso pour utilisateur {$user->id} : {$e->getMessage()}");
                }
            }

            // 4. Notifications aux amis (birthday_friend) si visibilité 'mutuals'
            if ($user->birthday_visibility === 'mutuals') {
                $mutuals = $user->mutualFollowers()
                    ->whereNull('scheduled_for_deletion_at')
                    ->where('status', '!=', 'deleted')
                    ->where('status', '!=', 'suspended')
                    ->where(function ($q): void {
                        $q->whereNull('suspended_until')->orWhere('suspended_until', '<=', now());
                    })
                    ->get();

                foreach ($mutuals as $friend) {
                    if ((int) $friend->id === (int) $user->id) {
                        continue;
                    }

                    if ($friend->isSuspended() || $friend->trashed()) {
                        continue;
                    }

                    // Vérification du blocage mutuel
                    if ($user->hasBlocked($friend) || $user->isBlockedBy($friend)) {
                        continue;
                    }

                    // Une seule notification par an par ami
                    $alreadyFriendNotified = BirthdayNotificationLog::query()
                        ->where('user_id', $user->id)
                        ->where('recipient_id', $friend->id)
                        ->where('year', $year)
                        ->where('type', 'friend')
                        ->exists();

                    if ($alreadyFriendNotified) {
                        continue;
                    }

                    // Recherche d'un fil direct existant entre ces 2 utilisateurs
                    $conversation = Conversation::query()
                        ->where('type', ConversationType::Direct)
                        ->whereNull('page_id')
                        ->whereNull('listing_id')
                        ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->whereNull('left_at'))
                        ->whereHas('members', fn ($q) => $q->where('user_id', $friend->id)->whereNull('left_at'))
                        ->first();

                    try {
                        $friend->notify(new BirthdayFriendNotification($user, $conversation?->id));
                        BirthdayNotificationLog::create([
                            'user_id' => $user->id,
                            'recipient_id' => $friend->id,
                            'year' => $year,
                            'type' => 'friend',
                        ]);
                        $friendsSent++;
                    } catch (\Throwable $e) {
                        Log::error("Échec de l'envoi notification anniversaire ami {$friend->id} pour utilisateur {$user->id} : {$e->getMessage()}");
                    }
                }
            }
        }

        return [
            'self_sent' => $selfSent,
            'friends_sent' => $friendsSent,
            'skipped' => $skipped,
        ];
    }
}
