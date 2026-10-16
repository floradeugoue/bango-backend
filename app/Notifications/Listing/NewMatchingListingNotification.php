<?php

namespace App\Notifications\Listing;

use App\Models\Listing\Listing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewMatchingListingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Listing $listing
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
        $priceFormatted = number_format((float) $this->listing->price, 0, ',', ' ').' '.($this->listing->currency ?? 'XAF');
        $location = trim(($this->listing->neighbourhood ? $this->listing->neighbourhood.', ' : '').$this->listing->city);

        return [
            'type' => 'new_matching_listing',
            'listing_id' => (string) $this->listing->id,
            'title' => "Nouvelle annonce disponible : {$this->listing->title}",
            'preview' => "{$priceFormatted} · {$location}",
            'price' => (float) $this->listing->price,
            'currency' => $this->listing->currency ?? 'XAF',
            'city' => $this->listing->city,
            'neighbourhood' => $this->listing->neighbourhood,
            'kind' => $this->listing->kind?->value ?? (string) $this->listing->kind,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
