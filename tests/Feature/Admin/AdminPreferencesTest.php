<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\AdminPreference;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createPrefSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => "pref.{$roleSlug}.".uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$operator, $sessionId, $csrfToken, $session];
}

it('stores and retrieves operator preferences per key and isolates them between operators', function () {
    [$operator1, $session1, $csrf1] = createPrefSession('moderation');
    [$operator2, $session2, $csrf2] = createPrefSession('support');

    $kycViews = [
        ['id' => 'v1', 'name' => 'KYC Douala', 'filters' => ['city' => 'Douala', 'status' => 'to_verify']],
        ['id' => 'v2', 'name' => 'Mes dossiers en retard', 'filters' => ['overdue' => true]],
    ];
    $accountColumns = ['display_name', 'city', 'badge', 'status', 'created_at'];

    // 1. Enregistrer views:/kyc (avec slash et deux-points dans l'URL)
    $putViews = $this->withUnencryptedCookie('bango_admin_session', $session1)
        ->withHeader('X-CSRF-TOKEN', $csrf1)
        ->putJson('/api/admin/me/preferences/views:/kyc', [
            'value' => $kycViews,
        ]);

    $putViews->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'key' => 'views:/kyc',
                'value' => $kycViews,
            ],
        ]);

    // 2. Enregistrer columns:/accounts:Comptes
    $putCols = $this->withUnencryptedCookie('bango_admin_session', $session1)
        ->withHeader('X-CSRF-TOKEN', $csrf1)
        ->putJson('/api/admin/me/preferences/columns:/accounts:Comptes', [
            'value' => $accountColumns,
        ]);

    $putCols->assertStatus(200)
        ->assertJsonPath('data.key', 'columns:/accounts:Comptes');

    // 3. Mettre à jour la même clé views:/kyc -> une seule ligne par opérateur et par clé
    $updatedViews = [
        ['id' => 'v1', 'name' => 'KYC Douala & Yaoundé', 'filters' => ['city' => ['Douala', 'Yaoundé']]],
    ];
    $this->withUnencryptedCookie('bango_admin_session', $session1)
        ->withHeader('X-CSRF-TOKEN', $csrf1)
        ->putJson('/api/admin/me/preferences/views:/kyc', [
            'value' => $updatedViews,
        ])
        ->assertStatus(200);

    expect(AdminPreference::where('user_id', $operator1->id)->where('key', 'views:/kyc')->count())->toBe(1);

    // 4. Récupérer toutes les préférences de l'opérateur 1 -> { data: { [cle]: valeur } }
    $get1 = $this->withUnencryptedCookie('bango_admin_session', $session1)
        ->getJson('/api/admin/me/preferences');

    $get1->assertStatus(200)
        ->assertJson([
            'data' => [
                'views:/kyc' => $updatedViews,
                'columns:/accounts:Comptes' => $accountColumns,
            ],
        ]);

    // 5. Vérifier l'isolation : l'opérateur 2 ne voit aucune préférence de l'opérateur 1
    $get2 = $this->withUnencryptedCookie('bango_admin_session', $session2)
        ->getJson('/api/admin/me/preferences');

    $get2->assertStatus(200);
    expect($get2->json('data'))->toBeEmpty();
});

it('rejects preference values exceeding 16 KB', function () {
    [$operator, $sessionId, $csrfToken] = createPrefSession('moderation');

    $tooLargePayload = [
        'blob' => str_repeat('A', 17000),
    ];

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson('/api/admin/me/preferences/views:/listings', [
            'value' => $tooLargePayload,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['value']);
});
