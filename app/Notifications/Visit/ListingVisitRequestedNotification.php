<?php

namespace App\Notifications\Visit;

use App\Models\Visit\ListingVisitRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ListingVisitRequestedNotification extends Notification implements ShouldQueue
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
        $listingTitle = $this->visitRequest->listing->title ?? 'votre bien';
        $prospectName = $this->visitRequest->prospect->display_name ?? ($this->visitRequest->prospect->name ?? 'Un prospect');

        return (new MailMessage)
            ->subject("Nouvelle demande de visite pour {$listingTitle}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("{$prospectName} a proposé des créneaux de visite pour {$listingTitle}.")
            ->action('Choisir un créneau', url("/landlord/listing-visit-requests/{$this->visitRequest->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $listingTitle = $this->visitRequest->listing->title ?? 'votre bien';
        $prospectName = $this->visitRequest->prospect->display_name ?: ($this->visitRequest->prospect->name ?? 'Un prospect');

        return [
            'type' => 'listing_visit_requested',
            'visit_request_id' => $this->visitRequest->id,
            'listing_id' => $this->visitRequest->listing_id,
            'prospect_id' => $this->visitRequest->prospect_id,
            'proposals' => $this->visitRequest->proposals ?? [],
            'title' => trans('notifications.listing_visit_requested.title', [], $locale),
            'message' => trans('notifications.listing_visit_requested.body', ['user' => $prospectName, 'title' => $listingTitle], $locale),
        ];
    }
}
