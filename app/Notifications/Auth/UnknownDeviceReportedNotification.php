<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UnknownDeviceReportedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $reason,
        public string $deviceHash
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

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
        return [
            'type' => 'unknown_device_reported',
            'reason' => $this->reason,
            'device_hash' => substr($this->deviceHash, 0, 16),
            'message' => 'Votre compte a été sécurisé et verrouillé suite au signalement d\'un appareil inconnu.',
            'reported_at' => now()->toIso8601String(),
        ];
    }
}
