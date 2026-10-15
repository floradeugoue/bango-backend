<?php

namespace Database\Factories\Story;

use App\Models\Story\StoryHighlight;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StoryHighlight>
 */
class StoryHighlightFactory extends Factory
{
    protected $model = StoryHighlight::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'title' => mb_substr(fake()->word(), 0, 20),
            'cover_story_id' => null,
            'cover_media_url' => 'https://storage.bango.app/stories/cover.jpg',
            'order' => 0,
        ];
    }
}
