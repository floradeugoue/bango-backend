<?php

namespace App\Notifications\Kyc;

use App\Models\Kyc\KycSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public KycSubmission $submission,
        public string $decision,
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
        $subject = match ($this->decision) {
            'approve' => 'Vérification d\'identité approuvée — BANGO',
            'reject' => 'Décision sur votre dossier de vérification KYC — Refusé',
            default => 'Compléments requis pour votre dossier de vérification KYC',
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Bonjour {$notifiable->name},");

        if ($this->decision === 'approve') {
            $mail->line('Votre dossier de vérification d\'identité a été approuvé avec succès.')
                ->line('Votre statut vérifié est désormais actif.');
        } elseif ($this->decision === 'reject') {
            $mail->line('Votre dossier de vérification d\'identité n\'a pas pu être validé.')
                ->line('Motif : '.($this->reason ?? 'Non conforme aux exigences de conformité.'));
        } else {
            $mail->line('Des pièces ou informations complémentaires sont requises pour finaliser votre dossier KYC.')
                ->line('Motif : '.($this->reason ?? 'Compléments demandés par l\'équipe conformité.'));
        }

        return $mail->action('Consulter mon dossier', url('/landlord/kyc'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = match ($this->decision) {
            'approve' => 'Votre dossier de vérification d\'identité a été approuvé avec succès.',
            'reject' => 'Votre dossier de vérification KYC a été refusé. Motif : '.($this->reason ?? 'Non conforme.'),
            default => 'Des compléments sont nécessaires pour votre dossier KYC. Motif : '.($this->reason ?? 'Compléments demandés.'),
        };

        return [
            'type' => 'kyc_decision',
            'submission_id' => $this->submission->id,
            'reference' => $this->submission->reference,
            'decision' => $this->decision,
            'status' => $this->submission->status?->value ?? (string) $this->submission->status,
            'reason' => $this->reason,
            'message' => $message,
        ];
    }
}
