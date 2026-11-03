<?php

namespace App\Notifications\Concerns;

use App\Services\Notification\NotificationPreferenceService;

trait RespectsNotificationPreferences
{
    /**
     * Détermine les canaux de diffusion selon les préférences de l'utilisateur.
     * Le canal 'database' est toujours conservé. Le canal 'mail' n'est activé que si permis.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->channelsFor($notifiable, $this);
    }
}
