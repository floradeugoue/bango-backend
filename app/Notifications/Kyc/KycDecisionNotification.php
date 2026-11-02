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
            if (! empty($this->submission->full_name)) {
                $mail->line("Conformément à votre pièce d'identité validée, votre profil s'appelle désormais « {$this->submission->full_name} ».");
            }
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
        $locale = $notifiable->locale ?? 'fr';

        $message = match ($this->decision) {
            'approve' => ! empty($this->submission->full_name)
                ? trans('notifications.kyc_decision.approved_name', ['name' => $this->submission->full_name], $locale)
                : trans('notifications.kyc_decision.approved', [], $locale),
            'reject' => trans('notifications.kyc_decision.rejected', ['reason' => $this->reason ?? ($locale === 'en' ? 'Non-compliant.' : 'Non conforme.')], $locale),
            default => trans('notifications.kyc_decision.changes_requested', ['reason' => $this->reason ?? ($locale === 'en' ? 'Additional info requested.' : 'Compléments demandés.')], $locale),
        };

        return [
            'type' => 'kyc_decision',
            'submission_id' => $this->submission->id,
            'reference' => $this->submission->reference,
            'decision' => $this->decision,
            'status' => $this->submission->status?->value ?? (string) $this->submission->status,
            'reason' => $this->reason,
            'full_name' => $this->submission->full_name,
            'title' => trans('notifications.kyc_decision.title', [], $locale),
            'message' => $message,
        ];
    }
}
