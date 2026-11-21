<?php

namespace App\Notifications\Profile;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ConfirmNeighbourhoodNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public const TYPE = 'neighbourhood_confirmation_required';

    public function __construct(
        public readonly ?string $city = null,
        public readonly ?string $neighbourhood = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';

        $bodyKey = $this->neighbourhood
            ? 'notifications.neighbourhood_confirmation_required.body_with_place'
            : 'notifications.neighbourhood_confirmation_required.body';

        return [
            'category' => 'account',
            'type' => self::TYPE,
            'action' => 'confirm_neighbourhood',
            'deep_link' => 'bango://profile/location',
            'city' => $this->city,
            'neighbourhood' => $this->neighbourhood,
            'title' => trans('notifications.neighbourhood_confirmation_required.title', [], $locale),
            'message' => trans($bodyKey, [
                'neighbourhood' => $this->neighbourhood ?? '',
                'city' => $this->city ?? '',
            ], $locale),
        ];
    }
}
