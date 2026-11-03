<?php

namespace App\Listeners\Notification;

use App\Models\User;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Notifications\Events\NotificationSending;

class CheckNotificationPreferences
{
    public function __construct(
        protected NotificationPreferenceService $preferenceService
    ) {}

    /**
     * Valide l'autorisation d'envoi d'une notification sur un canal donné selon les préférences de l'utilisateur.
     * Si cette méthode retourne false, Laravel annule immédiatement l'envoi sur ce canal (NotificationSkipped).
     */
    public function handle(NotificationSending $event): bool
    {
        // Le canal database est conservé inconditionnellement pour la cloche in-app
        if ($event->channel === 'database') {
            return true;
        }

        $notifiable = $event->notifiable;
        if (! ($notifiable instanceof User)) {
            return true;
        }

        return match ($event->channel) {
            'mail' => $this->preferenceService->isEmailAllowed($notifiable, $event->notification),
            'sms' => $this->preferenceService->isSmsAllowed($notifiable, $event->notification),
            'push' => $this->preferenceService->isPushAllowed($notifiable, $event->notification),
            default => true,
        };
    }
}
