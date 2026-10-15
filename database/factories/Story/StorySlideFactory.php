<?php

namespace Database\Factories\Story;

use App\Enums\Story\StoryMediaType;
use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StorySlide>
 */
class StorySlideFactory extends Factory
{
    protected $model = StorySlide::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'story_id' => Story::factory(),
            'media_type' => StoryMediaType::Image,
            'media_path' => 'stories/images/1/sample.jpg',
            'media_url' => 'https://storage.bango.app/stories/sample.jpg',
            'thumbnail_path' => 'stories/thumbnails/1/sample_thumb.jpg',
            'thumbnail_url' => 'https://storage.bango.app/stories/sample_thumb.jpg',
            'filter' => 'normal',
            'caption' => fake()->sentence(),
            'duration' => 5,
            'order' => 0,
            'sound_id' => null,
            'sound_metadata' => null,
            'metadata' => [
                'width' => 1080,
                'height' => 1920,
            ],
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'media_type' => StoryMediaType::Video,
            'media_path' => 'stories/videos/1/sample.mp4',
            'media_url' => 'https://storage.bango.app/stories/sample.mp4',
            'duration' => 15,
        ]);
    }
}
