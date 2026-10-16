<?php

namespace Database\Factories\Square;

use App\Enums\Square\PostAudience;
use App\Enums\Square\PostCommentsBy;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'page_id' => null,
            'body' => fake()->paragraph(),
            'audience' => PostAudience::Public,
            'comments_by' => PostCommentsBy::Everyone,
            'comments_closed' => false,
            'allow_share' => true,
            'hide_likes' => false,
            'is_edited' => false,
            'is_archived' => false,
            'place_label' => fake()->city(),
            'place_city' => 'Douala',
            'place_neighbourhood' => 'Akwa',
            'likes_count' => 0,
            'comments_count' => 0,
            'shares_count' => 0,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'is_archived' => true,
        ]);
    }
}
