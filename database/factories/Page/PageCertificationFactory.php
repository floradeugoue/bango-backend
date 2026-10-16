<?php

namespace Database\Factories\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Models\Page\Page;
use App\Models\Page\PageCertification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PageCertification>
 */
class PageCertificationFactory extends Factory
{
    protected $model = PageCertification::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'page_id' => Page::factory(),
            'submitted_by_id' => User::factory(),
            'status' => PageCertificationStatus::Pending,
            'rccm_number' => 'RC/DLA/2026/B/'.fake()->numerify('####'),
            'nif_number' => 'M'.fake()->numerify('##########').'A',
            'legal_representative_name' => fake()->name(),
            'legal_representative_id_type' => 'cni',
            'legal_representative_id_number' => fake()->numerify('##########'),
            'documents' => [
                'rccm_doc' => 'page_certifications/rccm.pdf',
                'nif_doc' => 'page_certifications/nif.pdf',
                'id_doc' => 'page_certifications/id.pdf',
            ],
            'rejection_reason' => null,
            'supplement_request_reason' => null,
            'corrections_count' => 0,
            'payment_transaction_id' => null,
            'decided_by_id' => null,
            'submitted_at' => now(),
            'decided_at' => null,
            'history' => [],
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => PageCertificationStatus::Approved,
            'decided_at' => now(),
            'decided_by_id' => User::factory(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PageCertificationStatus::Rejected,
            'rejection_reason' => 'Pièces non conformes ou illisibles.',
            'decided_at' => now(),
            'decided_by_id' => User::factory(),
        ]);
    }

    public function supplementRequested(): static
    {
        return $this->state(fn () => [
            'status' => PageCertificationStatus::SupplementRequested,
            'supplement_request_reason' => 'Veuillez joindre la quittance NIF récente.',
            'decided_at' => now(),
            'decided_by_id' => User::factory(),
        ]);
    }
}
