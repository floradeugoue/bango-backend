<?php

namespace App\Notifications\Social;

use App\Jobs\Notification\SendPushNotificationJob;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class SocialNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $type,
        public User $actor,
        public array $metadata = []
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
        $actorName = $this->actor->name ?? $this->actor->handle ?? 'Un utilisateur';
        $snippet = Str::limit($this->metadata['text'] ?? '', 40);

        $title = trans("notifications.{$this->type}.title", [], $locale);
        $body = trans("notifications.{$this->type}.body", [
            'user' => $actorName,
            'snippet' => $snippet,
        ], $locale);

        // Déclenchement automatique de la notification push
        if ($notifiable instanceof User) {
            SendPushNotificationJob::dispatch($notifiable, $title, $body, [
                'type' => $this->type,
                'actor_id' => $this->actor->id,
                'metadata' => $this->metadata,
            ]);
        }

        return [
            'type' => $this->type,
            'actor_id' => $this->actor->id,
            'actor_name' => $actorName,
            'title' => $title,
            'message' => $body,
            'metadata' => $this->metadata,
        ];
    }
}
