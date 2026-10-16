<?php

namespace App\Notifications\Page;

use App\Models\Page\PageCertification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageCertificationSupplementRequestedNotification extends Notification implements ShouldQueue
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
            ->subject("Pièces complémentaires requises pour {$pageName}")
            ->line("Des pièces justificatives complémentaires ont été demandées pour l'instruction de {$pageName}.")
            ->line("Détails : {$this->certification->supplement_request_reason}")
            ->action('Soumettre les corrections', url('/my-pages'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_certification_supplement_requested',
            'certification_id' => $this->certification->id,
            'page_id' => $this->certification->page_id,
            'page_name' => $this->certification->page->name ?? 'Page',
            'reason' => $this->certification->supplement_request_reason,
            'corrections_count' => $this->certification->corrections_count,
            'remaining_corrections' => max(0, PageCertification::MAX_ALLOWED_CORRECTIONS - $this->certification->corrections_count),
            'message' => 'Des informations complémentaires sont requises pour votre dossier de certification.',
        ];
    }
}
