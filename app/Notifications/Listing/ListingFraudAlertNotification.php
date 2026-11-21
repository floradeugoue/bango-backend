<?php

namespace App\Notifications\Listing;

use App\Models\Listing\Listing;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ListingFraudAlertNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public string $type = 'listing_fraud';

    public function __construct(
        public Listing $listing,
        public ?string $conversationId = null
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $listingTitle = (string) ($this->listing->title ?? '');
        $removedAt = ($this->listing->withdrawn_by_bango_at ?? $this->listing->deleted_at ?? now())->toIso8601String();

        $title = trans('notifications.listing_fraud.title', [], $locale);
        $body = trans('notifications.listing_fraud.body', ['title' => $listingTitle], $locale);

        return [
            'type' => 'listing_fraud',
            'category' => 'immo',
            'listing_id' => (string) $this->listing->id,
            'listingId' => (string) $this->listing->id,
            'listing_title' => $listingTitle,
            'listingTitle' => $listingTitle,
            'conversation_id' => $this->conversationId ? (string) $this->conversationId : null,
            'conversationId' => $this->conversationId ? (string) $this->conversationId : null,
            'removed_at' => $removedAt,
            'title' => $title,
            'body' => $body,
            'message' => $body,
        ];
    }
}
