<?php

namespace App\Events\Message;

use App\Models\Message\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.conversation.'.$this->message->conversation_id),
        ];

        // Ensure relations are loaded
        if ($this->message->relationLoaded('conversation') && $this->message->conversation) {
            foreach ($this->message->conversation->members as $member) {
                if ($member->user_id !== $this->message->sender_id) {
                    $channels[] = new PrivateChannel('chat.user.'.$member->user_id);
                }
            }
        }

        return $channels;
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (string) $this->message->id,
            'conversation_id' => (string) $this->message->conversation_id,
            'sender_id' => $this->message->sender_id,
            'type' => $this->message->type->value,
            'body' => $this->message->body,
            'client_message_id' => $this->message->client_message_id,
            'reply_to_message_id' => $this->message->reply_to_message_id,
            'attachment_url' => $this->message->attachment_url,
            'attachment_mime' => $this->message->attachment_mime,
            'attachment_size' => $this->message->attachment_size,
            'attachment_duration_ms' => $this->message->attachment_duration_ms,
            'metadata' => $this->message->metadata ?? [],
            'is_retracted' => (bool) $this->message->is_retracted,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
