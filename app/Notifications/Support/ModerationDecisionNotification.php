<?php

namespace App\Notifications\Support;

use App\Models\Support\Report;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ModerationDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public ?Report $report,
        public string $action,
        public string $justification,
        public ?Carbon $suspendedUntil = null
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->action) {
            'warn_author' => 'Avertissement de modération — BANGO',
            'warn_page' => 'Avertissement concernant votre page — BANGO',
            'suspend_page' => 'Suspension de votre page professionnelle — BANGO',
            'unsuspend_page' => 'Levée de suspension de votre page professionnelle — BANGO',
            'request_correction' => 'Demande de correction de contenu — BANGO',
            'suspend_author' => 'Suspension temporaire de votre compte — BANGO',
            'unsuspend_author' => 'Levée de la suspension de votre compte — BANGO',
            'unblock_author' => 'Déblocage de votre compte — BANGO',
            'restrict_author', 'restriction' => 'Restriction temporaire de votre compte — BANGO',
            'remove' => 'Retrait de contenu par la modération — BANGO',
            'hide' => 'Masquage de contenu par la modération — BANGO',
            default => 'Notification de modération de contenu — BANGO',
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Bonjour {$notifiable->name},");

        if ($this->action === 'warn_author') {
            $mail->line('Vous avez reçu un avertissement officiel de modération suite à un signalement de contenu.')
                ->line("Motif de l'avertissement : {$this->justification}")
                ->line('Veuillez vous conformer aux conditions d\'utilisation de la plateforme.');
        } elseif ($this->action === 'warn_page') {
            $mail->line('Votre page professionnelle a fait l\'objet d\'un avertissement officiel de modération suite à un signalement.')
                ->line("Motif de l'avertissement : {$this->justification}")
                ->line('Veuillez vous conformer aux conditions d\'utilisation de la plateforme.');
        } elseif ($this->action === 'suspend_page') {
            $mail->line('Votre page professionnelle a fait l\'objet d\'une mesure de suspension par l\'équipe de modération.')
                ->line("Motif : {$this->justification}")
                ->line('Votre compte personnel reste actif. Vous disposez d\'un droit de recours via notre centre de support.');
        } elseif ($this->action === 'unsuspend_page') {
            $mail->line('La suspension de votre page professionnelle a été levée par notre équipe de modération.')
                ->line("Précisions : {$this->justification}")
                ->line('Votre page est de nouveau active et accessible.');
        } elseif ($this->action === 'unsuspend_author') {
            $mail->line('La suspension de votre compte utilisateur a été levée par notre équipe de modération.')
                ->line("Précisions : {$this->justification}")
                ->line('Votre compte est de nouveau actif et pleinement accessible.');
        } elseif ($this->action === 'unblock_author') {
            $mail->line('Le blocage de votre compte utilisateur a été levé par notre équipe de modération.')
                ->line("Précisions : {$this->justification}")
                ->line('Votre compte est de nouveau actif et vous pouvez vous reconnecter.');
        } elseif ($this->action === 'request_correction') {
            $untilFormatted = $this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y à H:i') : 'dans le délai imparti';
            $mail->line("Une demande de correction a été émise sur votre contenu avant le {$untilFormatted}.")
                ->line("Motif / Précisions : {$this->justification}")
                ->line('À défaut de mise en conformité avant cette échéance, le contenu sera automatiquement masqué.');
        } elseif ($this->action === 'suspend_author') {
            $untilFormatted = $this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y à H:i') : 'ultérieurement';
            $mail->line("Votre compte a été suspendu jusqu'au {$untilFormatted} suite à un manquement aux règles communautaires.")
                ->line("Motif : {$this->justification}")
                ->line('Vous disposez d\'un droit de recours de 30 jours via notre centre de support.');
        } elseif (in_array($this->action, ['restrict_author', 'restriction'], true)) {
            $untilFormatted = $this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y à H:i') : 'ultérieurement';
            $mail->line("Votre compte a été restreint jusqu'au {$untilFormatted} suite à un manquement aux règles communautaires.")
                ->line("Motif : {$this->justification}")
                ->line('Durant cette période, vous ne pouvez pas publier ni commenter, mais vous pouvez toujours gérer vos biens et messages.');
        } elseif ($this->action === 'remove') {
            $mail->line('L\'un de vos contenus a été retiré par notre équipe de modération.')
                ->line("Motif : {$this->justification}")
                ->line('Vous pouvez consulter les détails et déposer un recours dans votre espace Support.');
        } elseif ($this->action === 'hide') {
            $mail->line('L\'un de vos contenus a été masqué par notre équipe de modération.')
                ->line("Motif : {$this->justification}")
                ->line('Vous pouvez consulter les détails et déposer un recours dans votre espace Support.');
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
        $locale = $notifiable->locale ?? 'fr';
        $message = match ($this->action) {
            'warn_author' => ($locale === 'en' ? 'Moderation warning: ' : 'Avertissement de modération : ').$this->justification,
            'warn_page' => ($locale === 'en' ? 'Page moderation warning: ' : 'Avertissement concernant votre page : ').$this->justification,
            'suspend_page' => ($locale === 'en' ? 'Page suspended: ' : 'Page professionnelle suspendue : ').$this->justification,
            'unsuspend_page' => ($locale === 'en' ? 'Page suspension lifted: ' : 'Suspension de la page levée : ').$this->justification,
            'unsuspend_author' => ($locale === 'en' ? 'Account suspension lifted: ' : 'Suspension du compte levée : ').$this->justification,
            'unblock_author' => ($locale === 'en' ? 'Account unblocked: ' : 'Déblocage du compte : ').$this->justification,
            'request_correction' => ($locale === 'en'
                ? 'Correction requested before '.($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y H:i') : 'deadline').'. Reason: '.$this->justification
                : 'Correction demandée avant le '.($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y à H:i') : "l'échéance").'. Motif : '.$this->justification),
            'suspend_author' => ($locale === 'en'
                ? 'Account suspended until '.($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y') : 'further notice').'. Reason: '.$this->justification
                : "Compte suspendu jusqu'au ".($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y') : 'nouvel ordre').'. Motif : '.$this->justification),
            'restrict_author', 'restriction' => ($locale === 'en'
                ? 'Account restricted until '.($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y') : 'further notice').'. Reason: '.$this->justification
                : "Compte restreint jusqu'au ".($this->suspendedUntil ? $this->suspendedUntil->format('d/m/Y') : 'nouvel ordre').'. Motif : '.$this->justification),
            'remove' => ($locale === 'en' ? 'Content removed: ' : 'Contenu retiré : ').$this->justification,
            'hide' => ($locale === 'en' ? 'Content hidden: ' : 'Contenu masqué : ').$this->justification,
            default => ($locale === 'en'
                ? "Moderation action ({$this->action}): {$this->justification}"
                : "Action de modération ({$this->action}) : {$this->justification}"),
        };

        return [
            'type' => 'moderation_decision',
            'report_reference' => $this->report?->reference,
            'action' => $this->action,
            'justification' => $this->justification,
            'suspended_until' => $this->suspendedUntil?->toISOString(),
            'message' => $message,
        ];
    }
}
