<?php

namespace App\Notifications\Visit;

use App\Models\Visit\VisitReservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitCancelledNotification extends Notification implements ShouldQueue
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
        $listingTitle = $this->reservation->listing->title ?? 'votre visite';

        return (new MailMessage)
            ->subject("Visite annulée : {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("La visite prévue pour {$listingTitle} a été annulée.")
            ->when($this->reason, fn ($mail) => $mail->line("Motif : {$this->reason}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'visit_cancelled',
            'reservation_id' => $this->reservation->id,
            'listing_id' => $this->reservation->listing_id,
            'reason' => $this->reason,
            'message' => "La visite pour {$this->reservation->listing->title} a été annulée.",
        ];
    }
}
