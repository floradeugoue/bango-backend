<?php

namespace App\Notifications\Message;

use App\Models\Message\Conversation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AddedToGroupNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Conversation $conversation,
        public User $addedBy
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
        $groupTitle = $this->conversation->title ?? 'un groupe';
        $addedByName = $this->addedBy->display_name ?: $this->addedBy->name;

        return [
            'type' => 'added_to_group',
            'conversation_id' => (string) $this->conversation->id,
            'group_title' => $groupTitle,
            'added_by_id' => $this->addedBy->id,
            'added_by_name' => $addedByName,
            'title' => trans('notifications.added_to_group.title', [], $locale),
            'message' => trans('notifications.added_to_group.body', ['user' => $addedByName, 'group' => $groupTitle], $locale),
            'created_at' => now()->toIso8601String(),
        ];
    }
}
