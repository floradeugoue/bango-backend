<?php

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $role = Role::where('slug', 'support')->first();
    $this->operator = User::factory()->create([
        'email' => 'agent@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);
});

function createTestSession(User $user, int $lastActivityMinutesAgo = 0, bool $revoked = false): array
{
    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit/TestBrowser',
        'two_factor_verified_at' => now(),
        'last_activity_at' => Carbon::now()->subMinutes($lastActivityMinutesAgo),
        'expires_at' => now()->addHours(12),
        'revoked_at' => $revoked ? now() : null,
    ]);

    return [$sessionId, $csrfToken, $session];
}

it('denies access without session cookie or with an invalid session ID', function () {
    $noCookieResponse = $this->getJson('/api/admin/auth/session');
    $noCookieResponse->assertStatus(401);

    $invalidResponse = $this->withCookie('bango_admin_session', 'nonexistent_session_id')
        ->getJson('/api/admin/auth/session');
    $invalidResponse->assertStatus(401);
});

it('denies access if admin session has expired due to 30 minutes of inactivity', function () {
    [$sessionId, $csrfToken] = createTestSession($this->operator, lastActivityMinutesAgo: 31);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/auth/session');

    $response->assertStatus(401)
        ->assertJsonFragment(['message' => 'Session administrative expirée par inactivité.']);
});

it('denies access if admin session has been revoked', function () {
    [$sessionId, $csrfToken] = createTestSession($this->operator, lastActivityMinutesAgo: 5, revoked: true);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/auth/session');

    $response->assertStatus(401)
        ->assertJsonFragment(['message' => 'Session administrative révoquée.']);
});

it('enforces CSRF token check on state-modifying requests', function () {
    [$sessionId, $csrfToken] = createTestSession($this->operator, lastActivityMinutesAgo: 2);

    // 1. Requête POST sans en-tête CSRF -> Rejetée 419
    $noCsrfResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->postJson('/api/admin/auth/session/touch');

    $noCsrfResponse->assertStatus(419)
        ->assertJsonFragment(['code' => 'CSRF_MISMATCH']);

    // 2. Requête POST avec mauvais jeton CSRF -> Rejetée 419
    $badCsrfResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', 'invalid_csrf_token')
        ->postJson('/api/admin/auth/session/touch');

    $badCsrfResponse->assertStatus(419);

    // 3. Requête POST avec jeton CSRF valide -> Acceptée 200
    $validCsrfResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withUnencryptedCookie('bango_admin_csrf', $csrfToken)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/auth/session/touch');

    $validCsrfResponse->assertStatus(200)
        ->assertJsonFragment(['message' => 'Session prolongée avec succès.']);
});

it('does not require CSRF token on safe GET requests', function () {
    [$sessionId, $csrfToken] = createTestSession($this->operator, lastActivityMinutesAgo: 2);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/auth/session');

    $response->assertStatus(200)
        ->assertJsonStructure(['operator', 'session', 'workspace']);
});

it('logs out and revokes server session, expiring both cookies', function () {
    [$sessionId, $csrfToken, $session] = createTestSession($this->operator, lastActivityMinutesAgo: 2);

    $logoutResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withUnencryptedCookie('bango_admin_csrf', $csrfToken)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/auth/logout');

    $logoutResponse->assertStatus(200)
        ->assertJsonFragment(['message' => 'Déconnexion effectuée avec succès.']);

    // Vérifie que la session en base de données a été marquée révoquée
    $session->refresh();
    expect($session->isRevoked())->toBeTrue();

    // Une tentative d'accès ultérieure avec le même cookie est rejetée (401)
    $followUpResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/auth/session');

    $followUpResponse->assertStatus(401);
});
