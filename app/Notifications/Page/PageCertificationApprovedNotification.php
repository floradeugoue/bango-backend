<?php

namespace App\Notifications\Page;

use App\Models\Page\PageCertification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageCertificationApprovedNotification extends Notification implements ShouldQueue
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
            ->subject("Félicitations ! Votre page {$pageName} est désormais certifiée")
            ->line("Le dossier de certification de {$pageName} a été approuvé. Votre badge de certification officielle est actif.")
            ->action('Voir ma page', url('/my-pages'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_certification_approved',
            'certification_id' => $this->certification->id,
            'page_id' => $this->certification->page_id,
            'page_name' => $this->certification->page->name ?? 'Page',
            'decided_at' => $this->certification->decided_at?->toIso8601String(),
            'message' => 'Félicitations, votre Page Professionnelle a été officiellement certifiée !',
        ];
    }
}
