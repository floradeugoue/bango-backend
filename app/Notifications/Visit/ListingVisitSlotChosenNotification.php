<?php

namespace App\Notifications\Visit;

use App\Models\Visit\ListingVisitRequest;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingVisitSlotChosenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ListingVisitRequest $visitRequest
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
        $listingTitle = $this->visitRequest->listing->title ?? 'votre demande de visite';
        $chosenTime = $this->visitRequest->chosen_slot_at ? Carbon::parse($this->visitRequest->chosen_slot_at)->translatedFormat('d F Y à H:i') : '';

        return (new MailMessage)
            ->subject("Créneau retenu pour votre visite de {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Le bailleur a retenu un créneau pour votre visite de {$listingTitle} : {$chosenTime}.")
            ->action('Voir la réservation', url("/visits/{$this->visitRequest->visit_reservation_id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $listingTitle = $this->visitRequest->listing->title ?? 'votre demande de visite';
        $chosenTime = $this->visitRequest->chosen_slot_at ? Carbon::parse($this->visitRequest->chosen_slot_at)->format('d/m/Y H:i') : '';

        return [
            'type' => 'listing_visit_slot_chosen',
            'visit_request_id' => $this->visitRequest->id,
            'visit_reservation_id' => $this->visitRequest->visit_reservation_id,
            'listing_id' => $this->visitRequest->listing_id,
            'chosen_slot_at' => $this->visitRequest->chosen_slot_at?->toISOString(),
            'title' => trans('notifications.listing_visit_slot_chosen.title', [], $locale),
            'message' => trans('notifications.listing_visit_slot_chosen.body', ['time' => $chosenTime, 'title' => $listingTitle], $locale),
        ];
    }
}
