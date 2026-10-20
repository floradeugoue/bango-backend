<?php

namespace Database\Factories\Listing;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = User::factory();

        return [
            'id' => (string) Str::uuid(),
            'owner_id' => $owner,
            'created_by_id' => $owner,
            'agent_id' => null,
            'submission_key' => (string) Str::uuid(),
            'title' => fake()->randomElement([
                'Studio meublé Almadies · 32 m²',
                'Appartement F3 vue mer Ngor',
                'Villa 4 pièces avec piscine Fann',
                'Chambre moderne en colocation Mermoz',
                'Duplex lumineux Plateau',
            ]),
            'description' => fake()->paragraph(3),
            'mode' => ListingMode::Rent,
            'kind' => ListingKind::Flat,
            'status' => ListingStatus::Published,
            'price' => fake()->numberBetween(75000, 450000),
            'currency' => 'XAF',
            'period' => 'month',
            'deposit_months' => 2,
            'advance_months' => 1,
            'visit_fee' => 10000,
            'charges' => 15000,
            'negotiable' => fake()->boolean(30),
            'utilities_included' => fake()->boolean(20),
            'bedrooms' => fake()->numberBetween(1, 4),
            'showers' => fake()->numberBetween(1, 3),
            'area' => fake()->randomFloat(2, 25, 250),
            'amenities' => ['furnished', 'meter', 'borehole', 'parking'],
            'features' => [
                ['id' => 'meter', 'label' => 'Compteur individuel'],
                ['id' => 'water', 'label' => 'Forage permanent'],
            ],
            'attributes' => ['2 chambres', '1 douche', 'Meublé'],
            'agent_visited' => fake()->boolean(40),
            'sponsored' => false,
            'city' => 'Dakar',
            'neighbourhood' => fake()->randomElement(['Almadies', 'Ngor', 'Ouakam', 'Mermoz', 'Fann']),
            'location' => 'près du carrefour Ngor',
            'address' => 'Rue des Almadies, Villa 42, Dakar',
            'lat' => fake()->latitude(14.65, 14.78),
            'lng' => fake()->longitude(-17.52, -17.40),
            'views_count' => fake()->numberBetween(10, 500),
            'saves_count' => fake()->numberBetween(0, 50),
            'pending_visits_count' => fake()->numberBetween(0, 5),
            'submitted_at' => now()->subDays(5),
            'published_at' => now()->subDays(4),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::Draft,
            'published_at' => null,
            'draft_step' => 'features',
        ]);
    }

    public function review(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::Review,
            'published_at' => null,
            'submitted_at' => now(),
            'review_due_at' => now()->addMinutes(3),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::Published,
            'published_at' => now()->subHour(),
        ]);
    }

    public function reserved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::Reserved,
            'published_at' => now()->subDays(2),
        ]);
    }

    public function taken(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::Taken,
            'published_at' => now()->subDays(10),
            'taken_at' => now()->subDays(1),
        ]);
    }

    public function sale(): static
    {
        return $this->state(fn (array $attributes) => [
            'mode' => ListingMode::Sale,
            'period' => null,
            'price' => fake()->numberBetween(35000000, 150000000),
            'deposit_months' => 0,
            'advance_months' => 0,
        ]);
    }
}
