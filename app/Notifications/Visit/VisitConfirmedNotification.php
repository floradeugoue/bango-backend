<?php

namespace App\Notifications\Visit;

use App\Models\Visit\VisitReservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitConfirmedNotification extends Notification implements ShouldQueue
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
        $listingTitle = $this->reservation->listing->title ?? 'votre visite';
        $slotTime = $this->reservation->slot->start_at->translatedFormat('d F Y à H:i');
        $address = $this->reservation->listing->address ?? 'Douala, Cameroun';

        return (new MailMessage)
            ->subject("Visite confirmée : {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Votre visite pour {$listingTitle} est désormais officiellement confirmée.")
            ->line("Date et heure : {$slotTime}")
            ->line("Adresse exacte : {$address}")
            ->action('Voir ma visite', url("/visits/{$this->reservation->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'visit_confirmed',
            'reservation_id' => $this->reservation->id,
            'listing_id' => $this->reservation->listing_id,
            'slot_id' => $this->reservation->slot_id,
            'scheduled_at' => $this->reservation->slot->start_at->toIso8601String(),
            'message' => "Visite confirmée pour {$this->reservation->listing->title}",
        ];
    }
}
