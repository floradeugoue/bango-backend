<?php

namespace Database\Factories\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    protected $model = Page::class;

    public function definition(): array
    {
        $name = fake()->company();
        $handle = Str::slug($name).'-'.fake()->unique()->numberBetween(100, 99999);

        return [
            'owner_id' => User::factory(),
            'name' => $name,
            'handle' => $handle,
            'description' => fake()->paragraph(),
            'sector' => fake()->randomElement(PageSector::cases()),
            'logo_url' => 'https://example.com/logo.png',
            'cover_url' => 'https://example.com/cover.png',
            'email' => fake()->companyEmail(),
            'phone' => '+237699'.fake()->numerify('######'),
            'website' => 'https://'.Str::slug($name).'.com',
            'address' => fake()->address(),
            'city' => 'Douala',
            'neighbourhood' => 'Akwa',
            'certification_status' => PageCertificationStatus::None,
            'status' => PageStatus::Active,
            'metadata' => null,
        ];
    }

    public function certified(): static
    {
        return $this->state(fn () => [
            'certification_status' => PageCertificationStatus::Approved,
        ]);
    }

    public function pendingCertification(): static
    {
        return $this->state(fn () => [
            'certification_status' => PageCertificationStatus::Pending,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => PageStatus::Suspended,
        ]);
    }

    public function realEstateAgency(): static
    {
        return $this->state(fn () => [
            'sector' => PageSector::RealEstateAgency,
        ]);
    }
}
