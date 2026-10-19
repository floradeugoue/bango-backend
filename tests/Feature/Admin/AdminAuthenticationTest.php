<?php

use App\Models\Admin\AdminChallenge;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('issues a 2FA challenge upon valid operator credentials without granting privileged session', function () {
    $role = Role::where('slug', 'moderation')->first();
    $operator = User::factory()->create([
        'email' => 'mod@bango.app',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/admin/auth/login', [
        'email' => 'mod@bango.app',
        'password' => 'StrongPassword123!',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'challenge_required' => true,
            'challenge_type' => 'totp',
        ])
        ->assertJsonStructure(['challenge_token', 'expires_in']);

    $challengeToken = $response->json('challenge_token');
    expect(AdminChallenge::where('token', $challengeToken)->exists())->toBeTrue();

    // Vérifie qu'aucun cookie de session n'a encore été émis
    $response->assertCookieMissing('bango_admin_session');

    // Vérifie que la tentative d'accès à la session protégée échoue (non authentifié)
    $sessionResponse = $this->getJson('/api/admin/auth/session');
    $sessionResponse->assertStatus(401);
});

it('rejects invalid credentials with anti-enumeration message', function () {
    $role = Role::where('slug', 'support')->first();
    User::factory()->create([
        'email' => 'support@bango.app',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $role->id,
    ]);

    $response = $this->postJson('/api/admin/auth/login', [
        'email' => 'support@bango.app',
        'password' => 'WrongPassword',
    ]);

    $response->assertStatus(401)
        ->assertJson(['message' => 'Identifiants invalides ou compte inactif.']);

    $nonExistentResponse = $this->postJson('/api/admin/auth/login', [
        'email' => 'ghost@bango.app',
        'password' => 'WrongPassword',
    ]);

    $nonExistentResponse->assertStatus(401)
        ->assertJson(['message' => 'Identifiants invalides ou compte inactif.']);
});

it('rejects suspended accounts and non-operator users', function () {
    $role = Role::where('slug', 'finance')->first();
    User::factory()->create([
        'email' => 'suspended@bango.app',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $role->id,
        'status' => 'suspended',
        'suspension_reason' => 'Violation de la politique de conformité',
    ]);

    $response = $this->postJson('/api/admin/auth/login', [
        'email' => 'suspended@bango.app',
        'password' => 'StrongPassword123!',
    ]);

    $response->assertStatus(403)
        ->assertJsonFragment(['message' => 'Ce compte opérateur est temporairement suspendu.']);

    // Utilisateur standard grand public
    $userRole = Role::where('slug', 'user')->first();
    User::factory()->create([
        'email' => 'client@example.com',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $userRole->id,
    ]);

    $clientResponse = $this->postJson('/api/admin/auth/login', [
        'email' => 'client@example.com',
        'password' => 'StrongPassword123!',
    ]);

    $clientResponse->assertStatus(403)
        ->assertJsonFragment(['message' => 'Accès refusé. Privilèges opérateur du back-office requis.']);
});

it('throttles repeated failed login attempts', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/admin/auth/login', [
            'email' => 'throttled@bango.app',
            'password' => 'BadPassword1!',
        ]);
    }

    $response = $this->postJson('/api/admin/auth/login', [
        'email' => 'throttled@bango.app',
        'password' => 'BadPassword1!',
    ]);

    $response->assertStatus(429);
});

it('validates 2FA and establishes authenticated session with HttpOnly and CSRF cookies', function () {
    $role = Role::where('slug', 'direction')->first();
    $operator = User::factory()->create([
        'email' => 'director@bango.app',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $role->id,
    ]);

    $loginResponse = $this->postJson('/api/admin/auth/login', [
        'email' => 'director@bango.app',
        'password' => 'StrongPassword123!',
    ]);

    $challengeToken = $loginResponse->json('challenge_token');

    // Configure un vrai secret TOTP sur le compte opérateur
    $totpService = app(TotpService::class);
    $rawSecret = $totpService->generateSecret();
    $operator->two_factor_secret = Crypt::encryptString($rawSecret);
    $operator->save();

    // 1. Vérifie qu'un code fictif comme '123456' est immédiatement rejeté
    $fakeCodeResponse = $this->postJson('/api/admin/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => '123456',
    ]);
    $fakeCodeResponse->assertStatus(422);

    // 2. Validation 2FA avec le vrai code TOTP calculé cryptographiquement
    $validCode = $totpService->calculateTotpForStep($rawSecret, (int) floor(time() / 30));

    $verifyResponse = $this->postJson('/api/admin/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => $validCode,
    ]);

    $verifyResponse->assertStatus(200)
        ->assertJsonStructure([
            'operator' => ['id', 'email', 'role', 'permissions'],
            'session' => ['expires_in_seconds', 'csrf_token'],
        ])
        ->assertCookie('bango_admin_session')
        ->assertCookie('bango_admin_csrf');

    // Vérifie que la valeur du cookie de session n'est PAS exposée dans le JSON
    expect($verifyResponse->json('session.bango_admin_session'))->toBeNull();

    // Vérifie que le challenge a bien été consommé
    $challenge = AdminChallenge::where('token', $challengeToken)->first();
    expect($challenge->isUsed())->toBeTrue();

    // Vérifie qu'une seconde utilisation du même challenge est rejetée
    $replayResponse = $this->postJson('/api/admin/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => $validCode,
    ]);
    $replayResponse->assertStatus(400);
});

it('allows 2FA recovery via single-use backup code', function () {
    $backupService = app(BackupCodeService::class);
    $generated = $backupService->generateCodes();

    $role = Role::where('slug', 'verification')->first();
    $operator = User::factory()->create([
        'email' => 'kyc@bango.app',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $role->id,
        'backup_codes' => $generated['hashed'],
    ]);

    $loginResponse = $this->postJson('/api/admin/auth/login', [
        'email' => 'kyc@bango.app',
        'password' => 'StrongPassword123!',
    ]);

    $challengeToken = $loginResponse->json('challenge_token');
    $validRecoveryCode = $generated['plain'][0];

    // Consommation du code de secours
    $recoveryResponse = $this->postJson('/api/admin/auth/2fa/recovery', [
        'challenge_token' => $challengeToken,
        'recovery_code' => $validRecoveryCode,
    ]);

    $recoveryResponse->assertStatus(200)
        ->assertCookie('bango_admin_session')
        ->assertCookie('bango_admin_csrf');

    // Vérifie que le code de secours a été consommé de manière atomique
    $operator->refresh();
    expect($backupService->getRemainingCount($operator))->toBe(7);

    // Une tentative de réutilisation immédiate du même code avec un nouveau challenge échoue
    $secondLogin = $this->postJson('/api/admin/auth/login', [
        'email' => 'kyc@bango.app',
        'password' => 'StrongPassword123!',
    ]);
    $newChallengeToken = $secondLogin->json('challenge_token');

    $reuseResponse = $this->postJson('/api/admin/auth/2fa/recovery', [
        'challenge_token' => $newChallengeToken,
        'recovery_code' => $validRecoveryCode,
    ]);
    $reuseResponse->assertStatus(422);
});
