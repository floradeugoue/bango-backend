<?php

namespace App\Notifications\Auth;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnknownDeviceReportedNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public string $reason,
        public string $deviceHash
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Alerte de sécurité : Appareil inconnu signalé — BANGO')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Un signalement concernant un appareil inconnu ou suspect a été enregistré pour votre compte.')
            ->line("Motif renseigné : {$this->reason}")
            ->line('Par mesure de sécurité préventive, votre compte a été temporairement verrouillé et toutes les sessions actives ont été révoquées.')
            ->line('Veuillez contacter le support ou la direction de BANGO pour déverrouiller vos accès.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';

        return [
            'type' => 'unknown_device_reported',
            'reason' => $this->reason,
            'device_hash' => substr($this->deviceHash, 0, 16),
            'title' => trans('notifications.unknown_device_reported.title', [], $locale),
            'message' => trans('notifications.unknown_device_reported.body', [], $locale),
            'reported_at' => now()->toIso8601String(),
        ];
    }
}
