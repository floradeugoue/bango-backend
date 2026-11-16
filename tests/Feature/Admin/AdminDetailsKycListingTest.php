<?php

namespace Tests\Feature\Admin;

use App\Enums\Kyc\KycStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Kyc\KycSubmission;
use App\Models\Listing\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createDetailTestAdminUser(string $roleSlug = 'direction'): User
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();

    return User::factory()->create([
        'email' => "admin.{$roleSlug}.".uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
        'password' => Hash::make('Password123!Secure'),
    ]);
}

function createDetailTestAdminSession(User $user): string
{
    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return $sessionId;
}

test('la fiche KYC GET admin/kyc/requests/{id} rend attempt et kind comme la liste', function () {
    $operator = createDetailTestAdminUser('direction');
    $sessionId = createDetailTestAdminSession($operator);

    $userRole = Role::where('slug', 'user')->firstOrFail();
    $user = User::factory()->create([
        'role_id' => $userRole->id,
        'name' => 'Arnaud Dupont',
        'status' => 'active',
    ]);

    // Première soumission
    $sub1 = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Rejected->value,
        'profile_category' => 'landlord',
        'created_at' => now()->subDays(2),
        'metadata' => [
            'kind' => 'new',
        ],
    ]);

    // Deuxième soumission pour le même utilisateur (tentative 2)
    $sub2 = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'created_at' => now()->subDay(),
        'metadata' => [
            'kind' => 'renewal',
            'attempt' => 2,
        ],
    ]);

    // Détail de la tentative 2
    $res2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$sub2->id}");

    $res2->assertStatus(200)
        ->assertJsonPath('data.attempt', 2)
        ->assertJsonPath('data.kind', 'renewal')
        ->assertJsonPath('data.reference', $sub2->reference);

    // Détail de la première tentative (calcul dynamique sans metadata attempt)
    $res1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$sub1->id}");

    $res1->assertStatus(200)
        ->assertJsonPath('data.attempt', 1)
        ->assertJsonPath('data.kind', 'new');
});

test('la fiche annonce GET admin/listings/{ref} rend reference comme la liste', function () {
    $operator = createDetailTestAdminUser('direction');
    $sessionId = createDetailTestAdminSession($operator);

    $userRole = Role::where('slug', 'user')->firstOrFail();
    $owner = User::factory()->create(['role_id' => $userRole->id]);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'title' => 'Superbe appartement vue mer',
    ]);

    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/listings/{$listing->reference}");

    $res->assertStatus(200)
        ->assertJsonPath('data.id', $listing->id)
        ->assertJsonPath('data.reference', $listing->reference)
        ->assertJsonPath('data.title', 'Superbe appartement vue mer');
});
