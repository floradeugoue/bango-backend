<?php

namespace App\Events\Message;

use App\Models\Message\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        foreach ($this->conversation->members as $member) {
            $channels[] = new PrivateChannel('chat.user.'.$member->user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'conversation.created';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => (string) $this->conversation->id,
            'type' => $this->conversation->type->value,
            'title' => $this->conversation->title,
            'listing_id' => $this->conversation->listing_id ? (string) $this->conversation->listing_id : null,
            'created_by_id' => $this->conversation->created_by_id,
            'created_at' => $this->conversation->created_at?->toIso8601String(),
        ];
    }
}
