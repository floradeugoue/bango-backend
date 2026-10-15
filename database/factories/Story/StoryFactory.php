<?php

namespace Database\Factories\Story;

use App\Enums\Story\StoryAudience;
use App\Enums\Story\StoryStatus;
use App\Models\Story\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Story>
 */
class StoryFactory extends Factory
{
    protected $model = Story::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'audience' => StoryAudience::Everyone,
            'allow_replies' => true,
            'allow_archive' => true,
            'status' => StoryStatus::Active,
            'published_at' => now(),
            'expires_at' => now()->addHours(24),
            'views_count' => 0,
            'poll_votes_count' => 0,
            'replies_count' => 0,
            'metadata' => [
                'source' => 'test',
            ],
        ];
    }

    public function followers(): static
    {
        return $this->state(fn () => [
            'audience' => StoryAudience::Followers,
        ]);
    }

    public function chosen(): static
    {
        return $this->state(fn () => [
            'audience' => StoryAudience::Chosen,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => StoryStatus::Expired,
            'expires_at' => now()->subMinutes(15),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => StoryStatus::Archived,
            'expires_at' => now()->subHours(25),
        ]);
    }
}
