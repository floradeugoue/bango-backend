<?php

namespace Database\Factories\Story;

use App\Models\Story\Story;
use App\Models\Story\StoryView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoryView>
 */
class StoryViewFactory extends Factory
{
    protected $model = StoryView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'story_id' => Story::factory(),
            'viewer_id' => User::factory(),
            'ip_address' => hash('sha256', fake()->ipv4()),
            'viewed_at' => now(),
        ];
    }
}
