<?php

namespace App\Notifications\Social;

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

        $pageId = $this->metadata['page_id'] ?? null;

        return [
            'type' => $this->type,
            'page_id' => $pageId,
            'actor_id' => $this->actor->id,
            'actor_name' => $actorName,
            'title' => $title,
            'message' => $body,
            'metadata' => $this->metadata,
        ];
    }
}
