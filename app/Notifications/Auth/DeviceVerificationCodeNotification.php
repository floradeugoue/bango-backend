<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DeviceVerificationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $expiresInSeconds = 600
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
        $minutes = (int) ceil($this->expiresInSeconds / 60);

        return (new MailMessage)
            ->subject('Code de vérification de votre appareil — BANGO')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Vous avez demandé le renvoi du code de confirmation pour votre appareil.')
            ->line("Votre code de vérification est : **{$this->code}**")
            ->line("Ce code est valable pendant {$minutes} minutes.")
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, signalez immédiatement cet appareil.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'device_verification_code',
            'code' => $this->code,
            'expires_in' => $this->expiresInSeconds,
            'message' => "Votre code de confirmation d'appareil est {$this->code}.",
            'sent_at' => now()->toIso8601String(),
        ];
    }
}
