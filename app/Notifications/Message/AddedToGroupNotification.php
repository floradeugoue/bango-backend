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
        $groupTitle = $this->conversation->title ?? 'un groupe';

        return [
            'type' => 'added_to_group',
            'conversation_id' => (string) $this->conversation->id,
            'group_title' => $groupTitle,
            'added_by_id' => $this->addedBy->id,
            'added_by_name' => $this->addedBy->name,
            'title' => "Ajouté au groupe « {$groupTitle} »",
            'message' => "{$this->addedBy->name} vous a ajouté au groupe « {$groupTitle} »",
            'created_at' => now()->toIso8601String(),
        ];
    }
}
