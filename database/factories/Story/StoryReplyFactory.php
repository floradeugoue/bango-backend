<?php

namespace Database\Factories\Story;

use App\Enums\Story\StoryReplyType;
use App\Models\Story\Story;
use App\Models\Story\StoryReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryReply>
 */
class StoryReplyFactory extends Factory
{
    protected $model = StoryReply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'story_id' => Story::factory(),
            'story_author_id' => User::factory(),
            'user_id' => User::factory(),
            'type' => StoryReplyType::Text,
            'text' => fake()->sentence(),
            'reaction' => null,
            'status' => 'pending',
            'idempotency_key' => (string) Str::uuid(),
            'metadata' => [
                'story_id' => (string) Str::uuid(),
                'caption' => fake()->sentence(),
            ],
        ];
    }

    public function reaction(string $emoji = '🔥'): static
    {
        return $this->state(fn () => [
            'type' => StoryReplyType::Reaction,
            'text' => null,
            'reaction' => $emoji,
        ]);
    }
}
