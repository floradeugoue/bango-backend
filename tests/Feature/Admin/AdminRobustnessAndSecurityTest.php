<?php

use App\Models\Admin\AdminChallenge;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    RateLimiter::clear('admin_login:127.0.0.1:target.security@bango.app');

    $this->supportRole = Role::where('slug', 'support')->first();
    $this->operator = User::factory()->create([
        'email' => 'target.security@bango.app',
        'password' => Hash::make('Admin123456!Secure'),
        'role_id' => $this->supportRole->id,
        'status' => 'active',
    ]);
});

it('enforces rate limiting on repeated failed login attempts', function () {
    $ip = '198.51.100.1';

    // 5 tentatives infructueuses
    for ($i = 0; $i < 5; $i++) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/admin/auth/login', [
                'email' => 'target.security@bango.app',
                'password' => 'WrongPassword123!',
            ]);
        $response->assertStatus(401);
    }

    // La 6ème tentative est bloquée par rate limiting (429)
    $blockedResponse = $this->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/admin/auth/login', [
            'email' => 'target.security@bango.app',
            'password' => 'WrongPassword123!',
        ]);

    $blockedResponse->assertStatus(429)
        ->assertJsonFragment(['code' => 'RATE_LIMITED']);
});

it('handles reporting an unknown or suspicious device and invalidates the challenge', function () {
    $challengeToken = bin2hex(random_bytes(32));
    $challenge = AdminChallenge::create([
        'token' => $challengeToken,
        'user_id' => $this->operator->id,
        'ip_address' => '127.0.0.1',
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson('/api/admin/auth/device/report', [
        'challenge_token' => $challengeToken,
        'reason' => 'Appareil inconnu non reconnu par l\'opérateur',
    ]);

    $response->assertStatus(200)
        ->assertJsonFragment(['message' => 'Signalement de sécurité enregistré. Des mesures de protection ont été appliquées.']);

    // Le challenge temporaire a été immédiatement invalidé
    $challenge->refresh();
    expect($challenge->used_at)->not->toBeNull();

    // L'appareil a été enregistré comme suspect/bloqué
    $this->assertDatabaseHas('admin_devices', [
        'user_id' => $this->operator->id,
        'is_trusted' => false,
    ]);
});

it('protects against SQL injection in search query', function () {
    $sessionId = bin2hex(random_bytes(32));
    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $this->operator->id,
        'csrf_token' => bin2hex(random_bytes(32)),
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // Tentatives d'injections SQL classiques
    $payloads = [
        "' OR '1'='1",
        "'; DROP TABLE users; --",
        "admin'--",
        '1 UNION SELECT null, null, null, null--',
    ];

    foreach ($payloads as $sqlInjection) {
        $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
            ->getJson('/api/admin/search?q='.urlencode($sqlInjection));

        $response->assertStatus(200)
            ->assertJsonStructure(['query', 'results', 'total_found']);
    }

    // Vérifie que la table users est toujours intacte
    expect(User::where('id', $this->operator->id)->exists())->toBeTrue();
});

it('prevents account enumeration on login and password reset', function () {
    // 1. Sur login : mot de passe erroné pour compte existant vs compte inexistant -> même status 401
    $existingResp = $this->postJson('/api/admin/auth/login', [
        'email' => 'target.security@bango.app',
        'password' => 'WrongPassword!',
    ]);

    $nonExistingResp = $this->postJson('/api/admin/auth/login', [
        'email' => 'ghost.unknown@bango.app',
        'password' => 'WrongPassword!',
    ]);

    expect($existingResp->status())->toBe(401);
    expect($nonExistingResp->status())->toBe(401);
    expect($existingResp->json('message'))->toBe($nonExistingResp->json('message'));

    // 2. Sur mot de passe oublié : même réponse 200 générique
    $forgotExisting = $this->postJson('/api/admin/auth/password/forgot', [
        'email' => 'target.security@bango.app',
    ]);
    $forgotGhost = $this->postJson('/api/admin/auth/password/forgot', [
        'email' => 'ghost.unknown@bango.app',
    ]);

    expect($forgotExisting->status())->toBe(200);
    expect($forgotGhost->status())->toBe(200);
    expect($forgotExisting->json('message'))->toBe($forgotGhost->json('message'));
});
