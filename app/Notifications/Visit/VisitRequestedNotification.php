<?php

namespace App\Notifications\Visit;

use App\Models\Visit\VisitReservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public VisitReservation $reservation
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
        $listingTitle = $this->reservation->listing->title ?? 'votre bien';
        $slotTime = $this->reservation->slot->start_at->translatedFormat('d F Y à H:i');

        return (new MailMessage)
            ->subject("Nouvelle demande de visite pour {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Une nouvelle visite a été réservée et réglée pour {$listingTitle}.")
            ->line("Date prévue : {$slotTime}")
            ->action('Voir la demande', url("/landlord/visits/{$this->reservation->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'visit_requested',
            'reservation_id' => $this->reservation->id,
            'listing_id' => $this->reservation->listing_id,
            'slot_id' => $this->reservation->slot_id,
            'prospect_id' => $this->reservation->prospect_id,
            'fee_amount' => $this->reservation->fee_amount,
            'scheduled_at' => $this->reservation->slot->start_at->toIso8601String(),
            'message' => "Nouvelle demande de visite pour {$this->reservation->listing->title}",
        ];
    }
}
