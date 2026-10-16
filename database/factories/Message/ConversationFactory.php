<?php

namespace Database\Factories\Message;

use App\Enums\Message\ConversationType;
use App\Models\Message\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'type' => ConversationType::Direct,
            'title' => null,
            'avatar_url' => null,
            'listing_id' => null,
            'created_by_id' => User::factory(),
            'last_message_at' => now(),
            'metadata' => null,
        ];
    }

    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ConversationType::Group,
            'title' => fake()->sentence(3),
        ]);
    }
}
