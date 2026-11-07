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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $this->directionRole = Role::where('slug', 'direction')->first();
    $this->moderationRole = Role::where('slug', 'moderation')->first();
    $this->supportRole = Role::where('slug', 'support')->first();
});

test('activation: finaliser invitation après le seul mot de passe sans étape TOTP (two_factor_method = off)', function () {
    $inviter = User::factory()->create([
        'role_id' => $this->directionRole->id,
        'email' => 'marie.dir@bango.app',
    ]);

    $rawToken = 'invitation_token_test_marie_123';
    $invitation = AdminInvitation::create([
        'email' => 'nouvel.operateur@bango.app',
        'token' => $rawToken,
        'role' => 'support',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(2),
    ]);

    // Étape 1 : définition du mot de passe
    $pwRes = $this->postJson("/admin/v1/auth/invitations/{$rawToken}/password", [
        'name' => 'Jean Support',
        'password' => 'BangoSecurePassword2026!',
    ]);
    $pwRes->assertStatus(200);

    // Étape finalisation directement après mot de passe (sans appeler l'étape TOTP)
    $compRes = $this->postJson("/admin/v1/auth/invitations/{$rawToken}/complete");
    $compRes->assertStatus(204);

    // Vérification du compte opérateur créé
    $operator = User::where('email', 'nouvel.operateur@bango.app')->first();
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

test('connexion: sans TOTP (off ou vide), ouvre la session directement sans challenge et enregistre l appareil', function () {
    $operator = User::factory()->create([
        'email' => 'op.nototp@bango.app',
        'password' => Hash::make('StrongPassword2026!'),
        'role_id' => $this->supportRole->id,
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

test('routes /me/security et /me/2fa/* : cycle complet setup, confirm, consultation et disable avec audit logs', function () {
    $operator = User::factory()->create([
        'email' => 'marie.op@bango.app',
        'password' => Hash::make('BangoPassword123!'),
        'role_id' => $this->directionRole->id,
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

    // Vérifier le log d'audit auth.2fa.enabled
    $logEnabled = AdminAuditLog::where('user_id', $operator->id)
        ->where('event', 'auth.2fa.enabled')
        ->first();
    expect($logEnabled)->not->toBeNull();

    // 5. GET admin/v1/me/security après activation
    $secRes2 = $this->withHeaders($authHeaders)->getJson('/admin/v1/me/security');
    $secRes2->assertStatus(200)
        ->assertJsonPath('two_factor_enabled', true)
        ->assertJsonPath('two_factor_method', 'totp')
        ->assertJsonPath('has_backup_codes', true)
        ->assertJsonPath('remaining_backup_codes', 10);
    expect($secRes2->json('two_factor_confirmed_at'))->not->toBeNull();

    // 6. Maintenant qu'il a le 2FA actif, sa prochaine connexion demande le challenge
    $loginWith2fa = $this->postJson('/admin/v1/auth/login', [
        'email' => 'marie.op@bango.app',
        'password' => 'BangoPassword123!',
    ]);
    $loginWith2fa->assertStatus(200)
        ->assertJsonPath('next', 'otp')
        ->assertJsonPath('challenge_required', true);

    // 7. POST admin/v1/me/2fa/disable avec mot de passe invalide -> 403
    $badDisable = $this->withHeaders($authHeaders)->postJson('/admin/v1/me/2fa/disable', [
        'password' => 'MauvaisMotDePasse!',
    ]);
    $badDisable->assertStatus(403);

    // 8. POST admin/v1/me/2fa/disable avec bon mot de passe -> succès
    $disableRes = $this->withHeaders($authHeaders)->postJson('/admin/v1/me/2fa/disable', [
        'password' => 'BangoPassword123!',
    ]);
    $disableRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('two_factor_enabled', false)
        ->assertJsonPath('two_factor_method', 'off');

    // Vérifier l'opérateur en DB
    $operator->refresh();
    expect($operator->two_factor_method)->toBe('off');
    expect($operator->two_factor_secret)->toBeNull();
    expect($operator->two_factor_confirmed_at)->toBeNull();

    // Vérifier le log d'audit auth.2fa.disabled
    $logDisabled = AdminAuditLog::where('user_id', $operator->id)
        ->where('event', 'auth.2fa.disabled')
        ->first();
    expect($logDisabled)->not->toBeNull();

    // 9. Nouvelle connexion après désactivation : directe sans challenge
    $loginAfterDisable = $this->postJson('/admin/v1/auth/login', [
        'email' => 'marie.op@bango.app',
        'password' => 'BangoPassword123!',
    ]);
    $loginAfterDisable->assertStatus(200)
        ->assertJsonPath('message', 'Connexion administrative réussie.');
    expect($loginAfterDisable->json('challengeId'))->toBeNull();
});
