<?php

namespace App\Notifications\Auth;

use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class UnknownLoginNotification extends Notification
{
    use Queueable, RespectsNotificationPreferences;

    public string $type = 'unknown_login';

    public string $place;

    public string $loggedInAt;

    public function __construct(
        public string $sessionId,
        public string $device,
        public string $city,
        public string $time,
        public ?string $country = null,
        ?string $place = null,
        ?string $loggedInAt = null,
    ) {
        $this->place = $place ?? ($country ? "{$city}, {$country}" : $city);
        $this->loggedInAt = $loggedInAt ?? now()->toIso8601String();
    }

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

        $title = trans('notifications.unknown_login.title', [], $locale);
        $body = trans('notifications.unknown_login.body', [
            'device' => $this->device,
            'city' => $this->city,
            'time' => $this->time,
        ], $locale);

        return [
            'type' => 'unknown_login',
            'category' => 'account',
            'session_id' => $this->sessionId,
            'sessionId' => $this->sessionId,
            'device' => $this->device,
            'device_name' => $this->device,
            'city' => $this->city,
            'country' => $this->country,
            'place' => $this->place,
            'time' => $this->time,
            'logged_in_at' => $this->loggedInAt,
            'title' => $title,
            'body' => $body,
            'message' => $body,
        ];
    }
}
