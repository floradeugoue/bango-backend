<?php

namespace App\Notifications\Page;

use App\Models\Page\PageCertification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageCertificationRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PageCertification $certification
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
        $pageName = $this->certification->page->name ?? 'votre page';

        return (new MailMessage)
            ->subject("Décision sur la certification de {$pageName}")
            ->line("Le dossier de certification de {$pageName} a été refusé.")
            ->line("Motif : {$this->certification->rejection_reason}")
            ->action('Consulter les détails', url('/my-pages'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_certification_rejected',
            'certification_id' => $this->certification->id,
            'page_id' => $this->certification->page_id,
            'page_name' => $this->certification->page->name ?? 'Page',
            'rejection_reason' => $this->certification->rejection_reason,
            'corrections_count' => $this->certification->corrections_count,
            'can_correct' => $this->certification->canSubmitCorrection(),
            'message' => 'Le dossier de certification de votre Page a été rejeté.',
        ];
    }
}
