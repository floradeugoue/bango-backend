<?php

namespace Database\Factories\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reel>
 */
class ReelFactory extends Factory
{
    protected $model = Reel::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'caption' => fake()->sentence(),
            'topic' => fake()->randomElement(['housing', 'food', 'fashion', 'music', 'tech', 'neighbourhood']),
            'status' => ReelStatus::Ready,
            'video_url' => 'https://storage.bango.app/reels/sample.mp4',
            'thumbnail_url' => 'https://storage.bango.app/reels/cover.jpg',
            'hls_url' => 'https://storage.bango.app/reels/manifest.m3u8',
            'duration' => 30,
            'width' => 1080,
            'height' => 1920,
            'aspect_ratio' => '9:16',
            'allow_download' => true,
            'comments_closed' => false,
            'subtitles' => fake()->sentence(),
            'subtitles_locale' => 'fr',
            'views_count' => 0,
            'likes_count' => 0,
            'dislikes_count' => 0,
            'comments_count' => 0,
            'shares_count' => 0,
            'published_at' => now(),
        ];
    }

    /**
     * Indicate that the reel is a draft.
     */
    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => ReelStatus::Draft,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the reel is currently processing.
     */
    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => ReelStatus::Processing,
            'published_at' => null,
        ]);
    }
}
