<?php

namespace Database\Factories\Page;

use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageMember>
 */
class PageMemberFactory extends Factory
{
    protected $model = PageMember::class;

    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'user_id' => User::factory(),
            'role' => PageRole::Editor,
            'status' => PageMemberStatus::Accepted,
            'invited_by_id' => null,
            'invited_at' => now()->subDays(1),
            'accepted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'role' => PageRole::Owner,
            'status' => PageMemberStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function editor(): static
    {
        return $this->state(fn () => [
            'role' => PageRole::Editor,
            'status' => PageMemberStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function moderator(): static
    {
        return $this->state(fn () => [
            'role' => PageRole::Moderator,
            'status' => PageMemberStatus::Accepted,
            'accepted_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PageMemberStatus::Pending,
            'accepted_at' => null,
            'invited_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => PageMemberStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}
