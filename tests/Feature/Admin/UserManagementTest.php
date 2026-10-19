<?php

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();

    // Create roles
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);

    // Create an admin user
    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => hash('sha256', $this->sessionId),
        'user_id' => $this->admin->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $this->withHeaders([
        'X-Bango-Admin-Session' => $this->sessionId,
        'X-CSRF-Token' => $this->csrfToken,
    ])->withUnencryptedCookies([
        'bango_admin_session' => $this->sessionId,
        'bango_admin_csrf' => $this->csrfToken,
    ]);
});

test('admin can list users', function () {
    User::factory()->count(5)->create(['role_id' => $this->userRole->id]);

    $response = $this->getJson('/api/admin/users');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'display_name', 'email', 'status', 'role'],
            ],
        ]);
});

test('admin can search and filter users', function () {
    $targetUser = User::factory()->create([
        'display_name' => 'John Target',
        'email' => 'target@example.com',
        'status' => 'suspended',
        'role_id' => $this->userRole->id,
    ]);

    User::factory()->create([
        'display_name' => 'Other User',
        'email' => 'other@example.com',
        'status' => 'active',
        'role_id' => $this->userRole->id,
    ]);

    $response = $this->getJson('/api/admin/users?display_name=John&status=suspended');

    $response->assertStatus(200)
        ->assertJsonPath('data.0.id', $targetUser->id)
        ->assertJsonCount(1, 'data');
});

test('admin can view user details without secrets', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    $response = $this->getJson("/api/admin/users/{$user->id}");

    $response->assertStatus(200)
        ->assertJsonPath('id', $user->id)
        ->assertJsonMissing(['password', 'two_factor_secret', 'backup_codes']);
});

test('admin can update user fillable fields', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    $response = $this->patchJson("/api/admin/users/{$user->id}", [
        'display_name' => 'Updated Name',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'display_name' => 'Updated Name',
    ]);
});

test('admin can suspend and block user', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id, 'status' => 'active']);

    $this->postJson("/api/admin/users/{$user->id}/suspend")
        ->assertStatus(200);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'suspended']);

    $this->postJson("/api/admin/users/{$user->id}/block")
        ->assertStatus(200);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'blocked']);
});

test('admin can unsuspend and unblock user', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id, 'status' => 'suspended']);

    $this->postJson("/api/admin/users/{$user->id}/unsuspend")
        ->assertStatus(200);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
});

test('admin can delete user', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    $this->deleteJson("/api/admin/users/{$user->id}")
        ->assertStatus(200);

    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

test('admin can view and revoke user sessions', function () {
    // Setup Passport clients for this test specifically
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);

    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    // Create passport token for user
    $token = $user->createToken('TestToken')->token;

    $response = $this->getJson("/api/admin/users/{$user->id}/sessions");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'sessions')
        ->assertJsonPath('sessions.0.id', $token->id);

    $this->deleteJson("/api/admin/users/{$user->id}/sessions/{$token->id}")
        ->assertStatus(200);

    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $token->id]);
});

test('admin can revoke all user sessions', function () {
    // Setup Passport clients for this test specifically
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);

    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    // Create multiple passport tokens
    $user->createToken('TestToken1');
    $user->createToken('TestToken2');

    $this->assertDatabaseCount('oauth_access_tokens', 2);

    $this->deleteJson("/api/admin/users/{$user->id}/sessions")
        ->assertStatus(200);

    $this->assertDatabaseCount('oauth_access_tokens', 0); // Both tokens should be revoked
});

test('non admin cannot access user management', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    $nonAdminSessionId = bin2hex(random_bytes(32));
    $nonAdminCsrf = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => hash('sha256', $nonAdminSessionId),
        'user_id' => $user->id,
        'csrf_token' => $nonAdminCsrf,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $this->withHeaders([
        'X-Bango-Admin-Session' => $nonAdminSessionId,
        'X-CSRF-Token' => $nonAdminCsrf,
    ])->withUnencryptedCookies([
        'bango_admin_session' => $nonAdminSessionId,
        'bango_admin_csrf' => $nonAdminCsrf,
    ])->getJson('/api/admin/users')
        ->assertStatus(403);
});
