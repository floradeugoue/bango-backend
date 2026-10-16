<?php

namespace Database\Factories\Message;

use App\Enums\Message\MessageType;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_id' => User::factory(),
            'type' => MessageType::Text,
            'body' => fake()->sentence(),
            'client_message_id' => (string) Str::uuid(),
            'reply_to_message_id' => null,
            'attachment_path' => null,
            'attachment_mime' => null,
            'attachment_size' => null,
            'attachment_duration_ms' => null,
            'metadata' => null,
            'is_retracted' => false,
            'retracted_at' => null,
        ];
    }

    public function voice(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MessageType::Voice,
            'body' => null,
            'attachment_path' => 'messages/voices/test.m4a',
            'attachment_mime' => 'audio/x-m4a',
            'attachment_size' => 125000,
            'attachment_duration_ms' => 4500,
        ]);
    }

    public function storyReply(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MessageType::StoryReply,
            'metadata' => [
                'story_id' => (string) Str::uuid(),
                'story_preview_card' => [
                    'title' => 'Story preview',
                ],
            ],
        ]);
    }
}
