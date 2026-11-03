<?php

namespace App\Notifications\Visit;

use App\Models\Visit\VisitReservation;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public VisitReservation $reservation
    ) {}

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
        $locale = $notifiable->locale ?? 'fr';
        $title = $this->reservation->listing->title ?? '';

        return [
            'type' => 'visit_requested',
            'reservation_id' => $this->reservation->id,
            'listing_id' => $this->reservation->listing_id,
            'slot_id' => $this->reservation->slot_id,
            'prospect_id' => $this->reservation->prospect_id,
            'fee_amount' => $this->reservation->fee_amount,
            'scheduled_at' => $this->reservation->slot->start_at->toIso8601String(),
            'title' => trans('notifications.visit_requested.title', [], $locale),
            'message' => trans('notifications.visit_requested.body', ['title' => $title], $locale),
        ];
    }
}
