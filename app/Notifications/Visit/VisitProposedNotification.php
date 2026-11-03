<?php

namespace App\Notifications\Visit;

use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VisitProposedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public VisitReservation $reservation,
        public VisitSlot $proposedSlot
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $listingTitle = $this->reservation->listing->title ?? 'votre demande de visite';
        $newSlotTime = $this->proposedSlot->start_at->translatedFormat('d F Y à H:i');

        return (new MailMessage)
            ->subject("Nouveau créneau proposé pour {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Le bailleur vous propose une alternative pour la visite de {$listingTitle}.")
            ->line("Nouveau créneau proposé : {$newSlotTime}")
            ->action('Accepter ou refuser la proposition', url("/visits/{$this->reservation->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $title = $this->reservation->listing->title ?? '';
        $slotTime = $this->proposedSlot->start_at->format('d/m H:i');

        return [
            'type' => 'visit_proposed',
            'reservation_id' => $this->reservation->id,
            'listing_id' => $this->reservation->listing_id,
            'original_slot_id' => $this->reservation->slot_id,
            'proposed_slot_id' => $this->proposedSlot->id,
            'proposed_at' => $this->proposedSlot->start_at->toIso8601String(),
            'title' => trans('notifications.visit_proposed.title', [], $locale),
            'message' => trans('notifications.visit_proposed.body', ['time' => $slotTime, 'title' => $title], $locale),
        ];
    }
}
