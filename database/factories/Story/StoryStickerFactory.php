<?php

namespace Database\Factories\Story;

use App\Enums\Story\StoryStickerType;
use App\Models\Story\StorySlide;
use App\Models\Story\StorySticker;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StorySticker>
 */
class StoryStickerFactory extends Factory
{
    protected $model = StorySticker::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'story_slide_id' => StorySlide::factory(),
            'type' => StoryStickerType::Text,
            'x' => 0.5,
            'y' => 0.5,
            'scale' => 1.0,
            'rotation' => 0.0,
            'data' => [
                'text' => fake()->words(3, true),
                'color' => '#ffffff',
            ],
        ];
    }

    public function poll(string $question = 'Aimez-vous ce contenu ?', array $options = ['Oui', 'Non']): static
    {
        return $this->state(fn () => [
            'type' => StoryStickerType::Poll,
            'data' => [
                'question' => $question,
                'options' => $options,
            ],
        ]);
    }
}
