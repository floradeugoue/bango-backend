<?php

namespace App\Notifications\Support;

use App\Models\Support\Report;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ModerationDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Report $report,
        public string $action,
        public string $justification,
        public ?Carbon $suspendedUntil = null
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
        $subject = match ($this->action) {
            'warn_author' => 'Avertissement de modération — BANGO',
            'suspend_author' => 'Suspension temporaire de votre compte — BANGO',
            default => 'Notification de modération de contenu — BANGO',
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Bonjour {$notifiable->name},");

        if ($this->action === 'warn_author') {
            $mail->line('Vous avez reçu un avertissement officiel de modération suite à un signalement de contenu.')
                ->line("Motif de l'avertissement : {$this->justification}")
                ->line('Veuillez vous conformer aux conditions d\'utilisation de la plateforme.');
        } elseif ($this->action === 'suspend_author') {
            $untilFormatted = $this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y à H:i') : 'ultérieurement';
            $mail->line("Votre compte a été suspendu jusqu'au {$untilFormatted} suite à un manquement aux règles communautaires.")
                ->line("Motif : {$this->justification}")
                ->line('Vous disposez d\'un droit de recours de 30 jours via notre centre de support.');
        } else {
            $mail->line('Une action de modération a été appliquée sur l\'un de vos contenus.')
                ->line("Motif : {$this->justification}");
        }

        return $mail->action('Consulter le centre d\'aide', url('/support'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $message = match ($this->action) {
            'warn_author' => "Avertissement de modération : {$this->justification}",
            'suspend_author' => "Compte suspendu jusqu'au ".($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y') : 'nouvel ordre').". Motif : {$this->justification}",
            default => "Action de modération ({$this->action}) : {$this->justification}",
        };

        return [
            'type' => 'moderation_decision',
            'report_reference' => $this->report->reference,
            'action' => $this->action,
            'justification' => $this->justification,
            'suspended_until' => $this->suspendedUntil?->toISOString(),
            'message' => $message,
        ];
    }
}
