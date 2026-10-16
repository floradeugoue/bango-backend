<?php

namespace App\Notifications\Message;

use App\Models\Message\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewStoryReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Message $message,
        public array $storyPreviewCard = []
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
        $senderName = $this->message->sender?->name ?? 'Un utilisateur';

        return [
            'type' => 'new_story_reply',
            'message_id' => (string) $this->message->id,
            'conversation_id' => (string) $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $senderName,
            'story_id' => $this->message->metadata['story_id'] ?? null,
            'body' => $this->message->body,
            'reaction' => $this->message->metadata['reaction'] ?? null,
            'story_preview_card' => $this->storyPreviewCard,
            'title' => "{$senderName} a répondu à votre story",
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
