<?php

use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminDevice;
use App\Models\Admin\AdminInvitation;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Auth\TotpService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $this->directionRole = Role::where('slug', 'direction')->first();
    $this->moderationRole = Role::where('slug', 'moderation')->first();
    $this->supportRole = Role::where('slug', 'support')->first();
    $this->financeRole = Role::where('slug', 'finance')->first();
    $this->verificationRole = Role::where('slug', 'verification')->first();
});

test('activation: rôle libre (modération) peut finaliser invitation sans étape TOTP (two_factor_method = off)', function () {
    $inviter = User::factory()->create([
        'role_id' => $this->directionRole->id,
        'email' => 'marie.dir@bango.app',
    ]);

    $rawToken = 'invitation_token_test_marie_123';
    $invitation = AdminInvitation::create([
        'email' => 'nouvel.moderateur@bango.app',
        'token' => $rawToken,
        'role' => 'moderation',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(2),
    ]);

    // Étape 1 : définition du mot de passe
    $pwRes = $this->postJson("/admin/v1/auth/invitations/{$rawToken}/password", [
        'name' => 'Jean Modération',
        'password' => 'BangoSecurePassword2026!',
    ]);
    $pwRes->assertStatus(200);

    // Étape finalisation directement après mot de passe (sans appeler l'étape TOTP)
    $compRes = $this->postJson("/admin/v1/auth/invitations/{$rawToken}/complete");
    $compRes->assertStatus(204);

    // Vérification du compte opérateur créé
    $operator = User::where('email', 'nouvel.moderateur@bango.app')->first();
    expect($operator)->not->toBeNull();
    expect($operator->status)->toBe('active');
    expect($operator->two_factor_method)->toBe('off');
    expect($operator->two_factor_secret)->toBeNull();
    expect($operator->two_factor_confirmed_at)->toBeNull();
    expect($operator->backup_codes)->toBeNull();
    expect(Hash::check('BangoSecurePassword2026!', $operator->password))->toBeTrue();

    // L'invitation est bien marquée acceptée
    $invitation->refresh();
    expect($invitation->accepted_at)->not->toBeNull();
});

test('activation: les rôles sensibles (vérification KYC, support, finance, direction) ne peuvent pas finaliser invitation sans TOTP', function () {
    $inviter = User::factory()->create([
        'role_id' => $this->directionRole->id,
        'email' => 'dir.inviter@bango.app',
    ]);

    foreach (['verification', 'support', 'finance', 'direction'] as $sensitiveRole) {
        $rawToken = "token_{$sensitiveRole}_".uniqid();
        AdminInvitation::create([
            'email' => "new.{$sensitiveRole}@bango.app",
            'token' => $rawToken,
            'role' => $sensitiveRole,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(2),
        ]);

        $pwRes = $this->postJson("/admin/v1/auth/invitations/{$rawToken}/password", [
            'name' => "Agent {$sensitiveRole}",
            'password' => 'BangoSecurePassword2026!',
        ]);
        $pwRes->assertStatus(200);

        // Tentative de finalisation sans avoir validé le code TOTP -> bloquée côté serveur (422)
        $compRes = $this->postJson("/admin/v1/auth/invitations/{$rawToken}/complete");
        $compRes->assertStatus(422)
            ->assertJsonPath('code', 'totp_required')
            ->assertJsonPath('two_factor_required', true);
    }
});

test('connexion: rôle libre (modération) sans TOTP ouvre la session directement sans challenge et enregistre l appareil', function () {
    $operator = User::factory()->create([
        'email' => 'op.nototp@bango.app',
        'password' => Hash::make('StrongPassword2026!'),
        'role_id' => $this->moderationRole->id,
        'status' => 'active',
        'two_factor_method' => 'off',
        'two_factor_secret' => null,
    ]);

    $loginRes = $this->postJson('/admin/v1/auth/login', [
        'email' => 'op.nototp@bango.app',
        'password' => 'StrongPassword2026!',
    ], [
        'User-Agent' => 'TestBrowser/1.0 (Windows NT 10.0)',
    ]);

    $loginRes->assertStatus(200)
        ->assertJsonPath('message', 'Connexion administrative réussie.')
        ->assertJsonStructure([
            'operator' => ['id', 'email', 'role', 'permissions'],
            'session' => ['csrf_token', 'expiresAt'],
        ]);

    // Pas de challengeId ni de next: otp
    expect($loginRes->json('next'))->toBeNull();
    expect($loginRes->json('challengeId'))->toBeNull();

    // Cookie de session bango_admin_session et csrf présents
    $cookies = $loginRes->headers->getCookies();
    $sessionCookie = null;
    $csrfCookie = null;
    foreach ($cookies as $c) {
        if ($c->getName() === 'bango_admin_session') {
            $sessionCookie = $c->getValue();
        }
        if ($c->getName() === 'bango_admin_csrf') {
            $csrfCookie = $c->getValue();
        }
    }
    expect($sessionCookie)->not->toBeNull();
    expect($csrfCookie)->not->toBeNull();

    // Vérification de l'enregistrement de l'appareil
    $deviceHash = hash('sha256', '127.0.0.1TestBrowser/1.0 (Windows NT 10.0)');
    $device = AdminDevice::where('user_id', $operator->id)->where('device_hash', $deviceHash)->first();
    expect($device)->not->toBeNull();
    expect($device->is_trusted)->toBeTrue();

    // Audit log
    $auditLog = AdminAuditLog::where('user_id', $operator->id)
        ->where('event', 'auth.login.success')
        ->latest('id')
        ->first();
    expect($auditLog)->not->toBeNull();
});

test('connexion: rôles sensibles (vérification KYC, support, finance, direction) sans TOTP exigent la mise en place du TOTP à la connexion', function () {
    $totpService = app(TotpService::class);

    $rolesToTest = [
        'verification' => $this->verificationRole,
        'support' => $this->supportRole,
        'finance' => $this->financeRole,
        'direction' => $this->directionRole,
    ];

    foreach ($rolesToTest as $slug => $role) {
        $operator = User::factory()->create([
            'email' => "sensitive.{$slug}@bango.app",
            'password' => Hash::make('StrongPassword2026!'),
            'role_id' => $role->id,
            'status' => 'active',
            'two_factor_method' => 'off',
            'two_factor_secret' => null,
        ]);

        $loginRes = $this->postJson('/admin/v1/auth/login', [
            'email' => "sensitive.{$slug}@bango.app",
            'password' => 'StrongPassword2026!',
        ]);

        // Ne doit PAS ouvrir de session directement : doit exiger la mise en place TOTP
        $loginRes->assertStatus(200)
            ->assertJsonPath('challenge_required', true)
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('two_factor_setup_required', true)
            ->assertJsonPath('next', 'setup_2fa')
            ->assertCookieMissing('bango_admin_session');

        $challengeToken = $loginRes->json('challenge_token');
        $setupSecret = $loginRes->json('secret');
        $otpauthUri = $loginRes->json('otpauth_uri');

        expect($challengeToken)->not->toBeEmpty();
        expect($setupSecret)->not->toBeEmpty();
        expect($otpauthUri)->toContain('otpauth://totp/');

        // Validation du premier code TOTP lors du challenge -> active le TOTP et ouvre la session
        $validCode = $totpService->calculateTotpForStep($setupSecret, (int) floor(time() / 30));

        $verifyRes = $this->postJson('/admin/v1/auth/2fa/verify', [
            'challenge_token' => $challengeToken,
            'code' => $validCode,
        ]);

        $verifyRes->assertStatus(200)
            ->assertJsonPath('two_factor_enabled', true)
            ->assertJsonStructure(['recoveryKeys', 'backup_codes', 'operator', 'session'])
            ->assertCookie('bango_admin_session')
            ->assertCookie('bango_admin_csrf');

        $operator->refresh();
        expect($operator->hasActiveTotp())->toBeTrue();
        expect($operator->two_factor_method)->toBe('totp');
        expect(Crypt::decryptString($operator->two_factor_secret))->toBe($setupSecret);
        expect($operator->backup_codes)->toBeArray()->toHaveCount(10);
    }
});

test('routes /me/security et /me/2fa/* : rôle libre (modération) peut activer et désactiver, rôle sensible ne peut pas désactiver', function () {
    $operator = User::factory()->create([
        'email' => 'mod.op@bango.app',
        'password' => Hash::make('BangoPassword123!'),
        'role_id' => $this->moderationRole->id,
        'status' => 'active',
        'two_factor_method' => 'off',
        'two_factor_secret' => null,
    ]);

    // Établir une session administrative
    $rawSessionId = bin2hex(random_bytes(32));
    $hashedSessionId = hash('sha256', $rawSessionId);
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $hashedSessionId,
        'user_id' => $operator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $authHeaders = [
        'X-Bango-Admin-Session' => $rawSessionId,
        'X-CSRF-Token' => $csrfToken,
        'X-Requested-With' => 'bango-admin',
    ];

    // 1. GET admin/v1/me/security avant activation
    $secRes = $this->withHeaders($authHeaders)->getJson('/admin/v1/me/security');
    $secRes->assertStatus(200)
        ->assertJsonPath('two_factor_enabled', false)
        ->assertJsonPath('two_factor_required', false)
        ->assertJsonPath('two_factor_method', 'off')
        ->assertJsonPath('two_factor_confirmed_at', null);

    // 2. POST admin/v1/me/2fa/setup -> { secret, otpauth_uri }
    $setupRes = $this->withHeaders($authHeaders)->postJson('/admin/v1/me/2fa/setup');
    $setupRes->assertStatus(200)
        ->assertJsonStructure(['secret', 'otpauth_uri']);

    $secret = $setupRes->json('secret');
    $otpauthUri = $setupRes->json('otpauth_uri');
    expect($secret)->not->toBeEmpty();
    expect($otpauthUri)->toContain('otpauth://totp/');

    // 3. POST admin/v1/me/2fa/confirm avec mauvais code -> 422
    $failConfirm = $this->withHeaders($authHeaders)->postJson('/admin/v1/me/2fa/confirm', [
        'code' => '000000',
    ]);
    $failConfirm->assertStatus(422)
        ->assertJsonPath('code', 'otp_invalid');

    // 4. POST admin/v1/me/2fa/confirm avec bon code -> 10 codes de secours
    $totpService = app(TotpService::class);
    $validCode = $totpService->calculateTotpForStep($secret, (int) floor(time() / 30));

    $confirmRes = $this->withHeaders($authHeaders)->postJson('/admin/v1/me/2fa/confirm', [
        'code' => $validCode,
    ]);
    $confirmRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['recoveryKeys', 'backup_codes', 'message']);

    $backupCodes = $confirmRes->json('recoveryKeys');
    expect($backupCodes)->toBeArray()->toHaveCount(10);

    // Vérifier en base pour l'opérateur
    $operator->refresh();
    expect($operator->two_factor_method)->toBe('totp');
    expect($operator->two_factor_secret)->not->toBeNull();
    expect(Crypt::decryptString($operator->two_factor_secret))->toBe($secret);
    expect($operator->two_factor_confirmed_at)->not->toBeNull();
    expect($operator->backup_codes)->toBeArray()->toHaveCount(10);

    // 5. GET admin/v1/me/security après activation
    $secRes2 = $this->withHeaders($authHeaders)->getJson('/admin/v1/me/security');
    $secRes2->assertStatus(200)
        ->assertJsonPath('two_factor_enabled', true)
        ->assertJsonPath('two_factor_method', 'totp')
        ->assertJsonPath('has_backup_codes', true)
        ->assertJsonPath('remaining_backup_codes', 10);

    // 6. Maintenant qu'il a le 2FA actif, sa prochaine connexion demande le challenge
    $loginWith2fa = $this->postJson('/admin/v1/auth/login', [
        'email' => 'mod.op@bango.app',
        'password' => 'BangoPassword123!',
    ]);
    $loginWith2fa->assertStatus(200)
        ->assertJsonPath('next', 'otp')
        ->assertJsonPath('challenge_required', true);

    // 7. POST admin/v1/me/2fa/disable avec bon mot de passe -> succès pour le rôle modération
    $disableRes = $this->withHeaders($authHeaders)->postJson('/admin/v1/me/2fa/disable', [
        'password' => 'BangoPassword123!',
    ]);
    $disableRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('two_factor_enabled', false)
        ->assertJsonPath('two_factor_method', 'off');

    // 8. Pour un rôle sensible (direction, finance, support, vérification), la désactivation est refusée (403)
    $sensitiveOp = User::factory()->create([
        'email' => 'finance.op@bango.app',
        'password' => Hash::make('BangoPassword123!'),
        'role_id' => $this->financeRole->id,
        'status' => 'active',
        'two_factor_method' => 'totp',
        'two_factor_secret' => Crypt::encryptString($secret),
        'two_factor_confirmed_at' => now(),
    ]);

    $rawSensSession = bin2hex(random_bytes(32));
    $sensCsrf = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => hash('sha256', $rawSensSession),
        'user_id' => $sensitiveOp->id,
        'csrf_token' => $sensCsrf,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $forbidDisable = $this->withHeaders([
        'X-Bango-Admin-Session' => $rawSensSession,
        'X-CSRF-Token' => $sensCsrf,
        'X-Requested-With' => 'bango-admin',
    ])->postJson('/admin/v1/me/2fa/disable', [
        'password' => 'BangoPassword123!',
    ]);

    $forbidDisable->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_mandatory');
});
