<?php

namespace App\Notifications\Page;

use App\Models\Page\PageCertification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageCertificationSubmittedNotification extends Notification implements ShouldQueue
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
            ->subject("Dossier de certification soumis pour {$pageName}")
            ->line("Le dossier de certification de {$pageName} a été soumis avec succès et est en attente d'examen administratif.")
            ->action('Suivre le dossier', url('/my-pages'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_certification_submitted',
            'certification_id' => $this->certification->id,
            'page_id' => $this->certification->page_id,
            'page_name' => $this->certification->page->name ?? 'Page',
            'submitted_at' => $this->certification->submitted_at?->toIso8601String(),
            'message' => 'Le dossier de certification de votre Page a été transmis aux équipes d\'examen.',
        ];
    }
}
