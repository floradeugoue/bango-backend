<?php

namespace App\Notifications\Page;

use App\Models\Page\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageSuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Page $page,
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
        return (new MailMessage)
            ->subject("Suspension de la page {$this->page->name}")
            ->line("La page professionnelle {$this->page->name} a fait l'objet d'une mesure de suspension.")
            ->line($this->reason ? "Motif : {$this->reason}" : 'Motif : Violation des conditions d\'utilisation BANGO.')
            ->action('Contacter le support', url('/support'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_suspended',
            'page_id' => $this->page->id,
            'page_name' => $this->page->name,
            'reason' => $this->reason,
            'message' => "La Page Professionnelle « {$this->page->name} » a été suspendue.",
        ];
    }
}
