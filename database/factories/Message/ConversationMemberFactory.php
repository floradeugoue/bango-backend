<?php

namespace Database\Factories\Message;

use App\Enums\Message\ConversationMemberRole;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMember>
 */
class ConversationMemberFactory extends Factory
{
    protected $model = ConversationMember::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'role' => ConversationMemberRole::Member,
            'is_muted' => false,
            'last_read_message_id' => null,
            'last_read_at' => null,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => ConversationMemberRole::Admin,
        ]);
    }
}
