<?php

namespace App\Notifications\Support;

use App\Models\Support\ModerationAppeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ModerationAppealDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ModerationAppeal $appeal,
        public string $resolution,
        public string $decisionNotes
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
        $subject = $this->resolution === 'revoked'
            ? 'Décision favorable sur votre recours — Sanction levée'
            : 'Décision sur votre recours de modération — Sanction maintenue';

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Concernant votre recours {$this->appeal->reference}.");

        if ($this->resolution === 'revoked') {
            $mail->line('Votre recours a été accepté. La sanction a été levée avec effet immédiat.')
                ->line("Commentaires de la commission : {$this->decisionNotes}");
        } else {
            $mail->line('Après réexamen de votre dossier, la commission a décidé de maintenir la sanction initiale.')
                ->line("Motif du rejet du recours : {$this->decisionNotes}");
        }

        return $mail->action('Consulter le centre d\'aide', url('/support'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $isRevoked = $this->resolution === 'revoked';
        $message = $isRevoked
            ? trans('notifications.moderation_appeal.revoked', ['reference' => $this->appeal->reference], $locale)
            : trans('notifications.moderation_appeal.rejected', ['reference' => $this->appeal->reference, 'notes' => $this->decisionNotes], $locale);

        return [
            'type' => 'appeal_decision',
            'appeal_reference' => $this->appeal->reference,
            'resolution' => $this->resolution,
            'status' => $this->appeal->status?->value ?? (string) $this->appeal->status,
            'decision_notes' => $this->decisionNotes,
            'message' => $message,
        ];
    }
}
