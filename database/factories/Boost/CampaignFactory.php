<?php

namespace Database\Factories\Boost;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Models\Boost\Campaign;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'boostable_type' => Post::class,
            'boostable_id' => fn (array $attributes) => Post::factory()->create(['user_id' => $attributes['user_id'] ?? $user])->id,
            'objective' => CampaignObjective::Reach,
            'budget_amount' => '5000.00',
            'budget_currency' => 'XAF',
            'duration_days' => 7,
            'start_at' => now(),
            'end_at' => now()->addDays(7),
            'targeting' => [
                'city' => 'Douala',
                'neighbourhoods' => ['Akwa', 'Bonanjo'],
                'radius_km' => 10,
                'age_min' => 18,
                'age_max' => 45,
                'interests' => ['immo', 'student'],
            ],
            'status' => CampaignStatus::Active,
            'stopped_reason' => null,
            'payment_method' => 'credit',
            'payment_reference' => null,
            'spent_amount' => '0.00',
            'impressions_count' => 0,
            'clicks_count' => 0,
            'reach_count' => 0,
            'metadata' => [
                'content_label' => 'Publication de test',
            ],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Draft,
            'start_at' => null,
            'end_at' => null,
            'payment_method' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Pending,
            'start_at' => null,
            'end_at' => null,
            'payment_method' => 'external',
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Paused,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Cancelled,
            'stopped_reason' => 'manual',
        ]);
    }
}
