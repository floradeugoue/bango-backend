<?php

namespace App\Notifications\Kyc;

use App\Models\Kyc\KycSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public KycSubmission $submission
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
            ->subject('Dossier KYC reçu — En cours d\'examen')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Votre dossier de vérification d\'identité a bien été reçu et est actuellement en cours d\'examen par la conformité BANGO.')
            ->line('Vous serez notifié dès qu\'une décision sera prise.')
            ->action('Voir mon statut', url('/landlord/kyc'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'kyc_submitted',
            'submission_id' => $this->submission->id,
            'status' => $this->submission->status->value,
            'message' => 'Votre dossier de vérification KYC a été soumis avec succès et est en cours d\'examen.',
        ];
    }
}
