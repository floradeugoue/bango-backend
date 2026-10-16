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
        $senderName = $this->message->sender?->name ?? 'Un utilisateur';
        $preview = match ($this->message->type->value) {
            'voice' => 'Note vocale',
            'image' => 'Photo',
            'story_reply' => 'Réponse à une story',
            default => $this->message->body ?? 'Nouveau message',
        };

        return [
            'type' => 'new_message',
            'message_id' => (string) $this->message->id,
            'conversation_id' => (string) $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $senderName,
            'message_type' => $this->message->type->value,
            'preview' => $preview,
            'title' => "Nouveau message de {$senderName}",
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
