<?php

namespace App\Notifications\Message;

use App\Models\Message\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Message $message) {}

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
        $senderName = $this->message->sender?->display_name ?: ($this->message->sender?->name ?? 'Un utilisateur');
        $preview = match ($this->message->type->value) {
            'voice' => trans('notifications.new_message.voice', [], $locale),
            'image' => trans('notifications.new_message.photo', [], $locale),
            'story_reply' => trans('notifications.new_message.story_reply', [], $locale),
            default => $this->message->body ?? trans('notifications.new_message.default', [], $locale),
        };

        return [
            'type' => 'new_message',
            'message_id' => (string) $this->message->id,
            'conversation_id' => (string) $this->message->conversation_id,
            'page_id' => $this->message->page_id ?? $this->message->conversation?->page_id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $senderName,
            'message_type' => $this->message->type->value,
            'preview' => $preview,
            'title' => trans('notifications.new_message.title', ['user' => $senderName], $locale),
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
