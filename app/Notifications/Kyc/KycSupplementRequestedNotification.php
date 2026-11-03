<?php

namespace App\Notifications\Kyc;

use App\Models\Kyc\KycSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycSupplementRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public KycSubmission $submission,
        public ?string $reason = null
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
        $subject = 'Pièces complémentaires requises pour votre dossier KYC';

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Des pièces ou informations complémentaires sont requises pour finaliser l\'instruction de votre dossier KYC.')
            ->line('Motif : '.($this->reason ?? 'Compléments demandés par l\'équipe conformité BANGO.'))
            ->action('Compléter mon dossier', url('/landlord/kyc'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';

        $title = $locale === 'en'
            ? 'Additional documents required for your KYC'
            : 'Compléments requis pour votre dossier KYC';

        $body = $locale === 'en'
            ? 'Additional documents requested for your KYC submission. Reason: '.($this->reason ?? 'Compliance review.')
            : 'Des compléments sont nécessaires pour votre dossier KYC. Motif : '.($this->reason ?? 'Instruction conformité.');

        return [
            'type' => 'kyc_supplement_requested',
            'submission_id' => $this->submission->id,
            'reference' => $this->submission->reference,
            'reason' => $this->reason,
            'status' => 'supplement_requested',
            'title' => $title,
            'message' => $body,
        ];
    }
}
