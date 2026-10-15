<?php

namespace App\Notifications\Visit;

use App\Models\Visit\VisitReservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public VisitReservation $reservation,
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
        $listingTitle = $this->reservation->listing->title ?? 'votre demande de visite';

        return (new MailMessage)
            ->subject("Demande de visite non validée : {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Votre demande de visite pour {$listingTitle} n'a pas pu être honorée.")
            ->when($this->reason, fn ($mail) => $mail->line("Raison : {$this->reason}"))
            ->line('Le montant payé a été intégralement recrédité sur votre solde BANGO.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'visit_declined',
            'reservation_id' => $this->reservation->id,
            'listing_id' => $this->reservation->listing_id,
            'reason' => $this->reason,
            'message' => "Votre demande de visite pour {$this->reservation->listing->title} a été déclinée. Les frais ont été remboursés.",
        ];
    }
}
