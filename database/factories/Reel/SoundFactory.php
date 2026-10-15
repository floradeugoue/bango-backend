<?php

namespace Database\Factories\Reel;

use App\Models\Reel\Sound;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Sound>
 */
class SoundFactory extends Factory
{
    protected $model = Sound::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'sound_'.Str::random(8);

        return [
            'id' => $id,
            'title' => fake()->words(2, true),
            'artist' => fake()->name(),
            'genre' => fake()->randomElement(['afro', 'makossa', 'coupe-decale', 'gospel', 'ambiance', 'highlife', 'amapiano', 'traditional']),
            'audio_url' => "https://storage.bango.app/sounds/{$id}.mp3",
            'duration_seconds' => fake()->numberBetween(15, 60),
            'base_uses' => fake()->numberBetween(10, 500),
            'uses_count' => 0,
            'is_original' => false,
            'is_locked' => false,
            'is_removed' => false,
        ];
    }
}
