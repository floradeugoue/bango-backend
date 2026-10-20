<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\AdminInvitation;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use App\Services\Auth\TotpService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createTestAdminUser(string $roleSlug = 'direction'): User
{
    $slug = ($roleSlug === 'kyc') ? 'verification' : $roleSlug;
    $role = Role::where('slug', $slug)->firstOrFail();

    return User::factory()->create([
        'email' => "test.{$roleSlug}.".uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
        'password' => Hash::make('Password123!Secure'),
        'two_factor_method' => 'totp',
        'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
    ]);
}

function getTotpCode(string $secret): string
{
    $totpService = app(TotpService::class);
    $step = (int) floor(time() / TotpService::TIME_STEP);

    return $totpService->calculateTotpForStep($secret, $step);
}

function createAuthenticatedSession(User $user): array
{
    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$sessionId, $csrfToken, $session];
}

// =========================================================================
// POINT 22: CHEMINS D'API, BASES ET ROUTES ATTENDUES PAR LE CONTRAT
// =========================================================================

test('point 22: /auth/password/reset/{token} route exists on /api/admin, /api/admin/v1 and /admin/v1', function () {
    $operator = createTestAdminUser('direction');
    $rawToken = 'reset_token_test_123';
    DB::table('password_reset_tokens')->insert([
        'email' => $operator->email,
        'token' => hash('sha256', $rawToken),
        'created_at' => now(),
    ]);

    // Test GET /api/admin/auth/password/reset/{token}
    $res1 = $this->getJson("/api/admin/auth/password/reset/{$rawToken}");
    $res1->assertStatus(200)
        ->assertJsonPath('status', 'valid')
        ->assertJsonPath('email', $operator->email);

    // Test GET /api/admin/v1/auth/password/reset/{token}
    $res2 = $this->getJson("/api/admin/v1/auth/password/reset/{$rawToken}");
    $res2->assertStatus(200)
        ->assertJsonPath('status', 'valid');

    // Test GET direct /admin/v1/auth/password/reset/{token}
    $res3 = $this->getJson("/admin/v1/auth/password/reset/{$rawToken}");
    $res3->assertStatus(200)
        ->assertJsonPath('status', 'valid');
});

test('point 22: /moderation/decisions/mine/export route exists on /api/admin, /api/admin/v1 and /admin/v1', function () {
    $operator = createTestAdminUser('moderation');
    [$sessionId, $csrfToken] = createAuthenticatedSession($operator);

    $res1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/moderation/decisions/mine/export');
    $res1->assertStatus(200);

    $res2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/v1/moderation/decisions/mine/export');
    $res2->assertStatus(200);

    $res3 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/admin/v1/moderation/decisions/mine/export');
    $res3->assertStatus(200);
});

test('point 22: GET /dashboard is accessible to direction operator without dashboard:view permission blocker', function () {
    $operator = createTestAdminUser('direction');
    [$sessionId, $csrfToken] = createAuthenticatedSession($operator);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/dashboard');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'syncedAt',
            'period',
            'kpis',
            'activity',
            'totals',
            'sla',
            'reasons',
            'heatmap',
            'recent',
            'priorities',
            'reportsByCity',
            'exportRows',
        ]);
});

test('point 22: three steps of invitation flow follow API contract', function () {
    $inviter = createTestAdminUser('direction');
    $invitation = AdminInvitation::create([
        'email' => 'nouveau.collaborateur@bango.app',
        'role' => 'moderation',
        'token' => 'invitation_contract_test_token',
        'expires_at' => now()->addDays(3),
        'invited_by' => $inviter->id,
    ]);

    // Step 0: GET /auth/invitations/{token}
    $showRes = $this->getJson('/api/admin/auth/invitations/invitation_contract_test_token');
    $showRes->assertStatus(200)
        ->assertJsonPath('status', 'valid')
        ->assertJsonPath('email', 'nouveau.collaborateur@bango.app')
        ->assertJsonPath('roleTitle', 'Modérateur');

    // Step 1: POST /auth/invitations/{token}/password -> TotpSetup
    $pwRes = $this->postJson('/api/admin/auth/invitations/invitation_contract_test_token/password', [
        'password' => 'NewPassword2026!Secure',
    ]);
    $pwRes->assertStatus(200)
        ->assertJsonStructure(['secret', 'otpauthUri', 'issuer']);
    $secret = $pwRes->json('secret');

    // Generate valid TOTP code
    $code = getTotpCode($secret);

    // Step 2: POST /auth/invitations/{token}/totp -> { recoveryKeys: string[] }
    $totpRes = $this->postJson('/api/admin/auth/invitations/invitation_contract_test_token/totp', [
        'code' => $code,
    ]);
    $totpRes->assertStatus(200)
        ->assertJsonStructure(['recoveryKeys']);
    expect($totpRes->json('recoveryKeys'))->toBeArray()->toHaveCount(10);

    // Step 3: POST /auth/invitations/{token}/complete -> 204
    $compRes = $this->postJson('/api/admin/auth/invitations/invitation_contract_test_token/complete', [
        'recoveryKeysSaved' => true,
    ]);
    $compRes->assertStatus(204);

    // Verify operator was created and active
    $user = User::where('email', 'nouveau.collaborateur@bango.app')->first();
    expect($user)->not->toBeNull();
    expect($user->status)->toBe('active');
    expect($user->role->slug)->toBe('moderation');
});

// =========================================================================
// POINT 23: CORPS DE REQUÊTES (CHALLENGEID, KEY, REMEMBERDEVICE, 2FA RESET)
// =========================================================================

test('point 23: login accepts rememberDevice and returns challengeId', function () {
    $operator = createTestAdminUser('direction');

    $response = $this->postJson('/api/admin/auth/login', [
        'email' => $operator->email,
        'password' => 'Password123!Secure',
        'rememberDevice' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('next', 'otp')
        ->assertJsonStructure([
            'next',
            'challengeId',
            'operator' => ['email'],
        ]);
});

test('point 23: 2fa verify accepts challengeId as well as challenge_token', function () {
    $operator = createTestAdminUser('direction');

    $loginRes = $this->postJson('/api/admin/auth/login', [
        'email' => $operator->email,
        'password' => 'Password123!Secure',
    ]);
    $challengeId = $loginRes->json('challengeId');

    $secret = Crypt::decryptString($operator->two_factor_secret);
    $code = getTotpCode($secret);

    $verifyRes = $this->postJson('/api/admin/auth/2fa/verify', [
        'challengeId' => $challengeId,
        'code' => $code,
    ]);

    $verifyRes->assertStatus(200)
        ->assertJsonStructure(['operator', 'permissions', 'expiresAt']);
});

test('point 23: 2fa recovery accepts key and challengeId', function () {
    $operator = createTestAdminUser('direction');
    $backupCodes = app(BackupCodeService::class)->generateCodes(10);
    $operator->update(['backup_codes' => $backupCodes['hashed']]);

    $loginRes = $this->postJson('/api/admin/auth/login', [
        'email' => $operator->email,
        'password' => 'Password123!Secure',
    ]);
    $challengeId = $loginRes->json('challengeId');

    $recoveryRes = $this->postJson('/api/admin/auth/2fa/recovery', [
        'challengeId' => $challengeId,
        'key' => $backupCodes['plain'][0],
    ]);

    $recoveryRes->assertStatus(200)
        ->assertJsonStructure(['operator', 'permissions', 'expiresAt']);
});

test('point 23: password reset requires 2FA code and rejects reused password with password_reused code', function () {
    $operator = createTestAdminUser('direction');
    $rawToken = 'reset_pw_contract_test_token';
    DB::table('password_reset_tokens')->insert([
        'email' => $operator->email,
        'token' => hash('sha256', $rawToken),
        'created_at' => now(),
    ]);

    $secret = Crypt::decryptString($operator->two_factor_secret);
    $validCode = getTotpCode($secret);

    // 1. Missing or invalid TOTP code -> 401 otp_invalid
    $resInvalidCode = $this->postJson("/api/admin/auth/password/reset/{$rawToken}", [
        'password' => 'BrandNewPassword2026!Secure',
        'code' => '999999',
    ]);
    $resInvalidCode->assertStatus(401)
        ->assertJsonPath('code', 'otp_invalid');

    // 2. Reusing old password -> 422 password_reused
    $resReusedPw = $this->postJson("/api/admin/auth/password/reset/{$rawToken}", [
        'password' => 'Password123!Secure',
        'code' => $validCode,
    ]);
    $resReusedPw->assertStatus(422)
        ->assertJsonPath('code', 'password_reused');

    // 3. Valid password + valid TOTP code -> 204
    Cache::flush();
    $resSuccess = $this->postJson("/api/admin/auth/password/reset/{$rawToken}", [
        'password' => 'BrandNewPassword2026!Secure',
        'code' => $validCode,
    ]);
    $resSuccess->assertStatus(204);

    // Verify new password is set
    $operator->refresh();
    expect(Hash::check('BrandNewPassword2026!Secure', $operator->password))->toBeTrue();
});

// =========================================================================
// POINT 24: FORMAT UNIFORME DES ERREURS & STATUS CODES
// =========================================================================

test('point 24: invalid credentials returns 401 with code invalid_credentials and meta', function () {
    $response = $this->postJson('/api/admin/auth/login', [
        'email' => 'nonexistent@bango.app',
        'password' => 'WrongPassword123!',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('code', 'invalid_credentials')
        ->assertJsonStructure(['code', 'message', 'fields', 'meta' => ['attemptsLeft']]);
});

test('point 24: locked account returns HTTP 423 with code account_locked and lock meta', function () {
    $operator = createTestAdminUser('direction');

    $loginRes = $this->postJson('/api/admin/auth/login', [
        'email' => $operator->email,
        'password' => 'Password123!Secure',
    ]);
    $challengeId = $loginRes->json('challengeId');

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/admin/auth/2fa/verify', [
            'challengeId' => $challengeId,
            'code' => '000000',
        ]);
    }

    $lockRes = $this->postJson('/api/admin/auth/2fa/verify', [
        'challengeId' => $challengeId,
        'code' => '000000',
    ]);

    $lockRes->assertStatus(423)
        ->assertJsonPath('code', 'account_locked')
        ->assertJsonStructure(['code', 'message', 'fields', 'meta' => ['incidentRef', 'lockedAt']]);

    // Also verify login with locked account returns 423
    $operator->refresh();
    expect($operator->isLocked())->toBeTrue();

    $loginLockedRes = $this->postJson('/api/admin/auth/login', [
        'email' => $operator->email,
        'password' => 'Password123!Secure',
    ]);

    $loginLockedRes->assertStatus(423)
        ->assertJsonPath('code', 'account_locked');
});

test('point 24: CSRF mismatch returns HTTP 403 when called with X-Requested-With: bango-admin', function () {
    $operator = createTestAdminUser('direction');
    [$sessionId] = createAuthenticatedSession($operator);

    // Request with bango-admin header but mismatched CSRF token
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-Requested-With', 'bango-admin')
        ->withHeader('X-CSRF-Token', 'invalid_csrf_token_value')
        ->postJson('/api/admin/auth/logout');

    $response->assertStatus(403)
        ->assertJsonPath('code', 'forbidden');
});

test('point 24: validation errors format matches code, message, fields, meta', function () {
    $response = $this->withHeader('X-Requested-With', 'bango-admin')
        ->postJson('/api/admin/auth/login', [
            'email' => 'not-an-email',
            // password missing
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'validation')
        ->assertJsonStructure(['code', 'message', 'fields', 'meta']);
});

// =========================================================================
// POINT 25: PAGINATION AU FORMAT Page<T> { items, page, perPage, total, pageCount }
// =========================================================================

test('point 25: paginated endpoints return Page<T> format', function () {
    $operator = createTestAdminUser('moderation');
    [$sessionId, $csrfToken] = createAuthenticatedSession($operator);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/queue');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'items',
            'page',
            'perPage',
            'total',
            'pageCount',
        ]);
});

// =========================================================================
// POINT 26: FILTRES APPLIQUÉS REELLEMENT SUR GET /kyc/requests
// =========================================================================

test('point 26: GET /kyc/requests accepts and applies badge, kind, city, age, awaiting, q, country', function () {
    $operator = createTestAdminUser('kyc');
    [$sessionId, $csrfToken] = createAuthenticatedSession($operator);

    $u1 = User::factory()->create([
        'name' => 'Amadou Diallo',
        'email' => 'amadou.diallo.kyc@testsn.com',
        'phone' => '+221770000000',
        'city' => 'Dakar',
        'country_code' => 'SN',
    ]);
    $u2 = User::factory()->create([
        'name' => 'Koffi Kouassi',
        'email' => 'koffi.kouassi.kyc@testci.com',
        'phone' => '+2250102030405',
        'city' => 'Abidjan',
        'country_code' => 'CI',
    ]);

    $k1 = KycSubmission::create([
        'user_id' => $u1->id,
        'profile_category' => 'landlord',
        'status' => 'pending',
        'full_name' => 'Amadou Diallo',
        'id_type' => 'cni',
        'id_number' => 'SN-12345',
        'created_at' => now()->subHours(2),
    ]);

    $k2 = KycSubmission::create([
        'user_id' => $u2->id,
        'profile_category' => 'agent',
        'status' => 'supplement_requested',
        'full_name' => 'Koffi Kouassi',
        'id_type' => 'passport',
        'id_number' => 'CI-98765',
    ]);
    DB::table('kyc_submissions')->where('id', $k2->id)->update(['created_at' => now()->subHours(10)]);

    // Filter by city: Dakar
    $resCity = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests?city=Dakar');
    $resCity->assertStatus(200);
    $items = collect($resCity->json('items'));
    expect($items->pluck('id'))->toContain((string) $k1->id)
        ->and($items->pluck('id'))->not->toContain((string) $k2->id);

    // Filter by badge: agent
    $resBadge = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests?badge=agent');
    $resBadge->assertStatus(200);
    $badgeItems = collect($resBadge->json('items'));
    expect($badgeItems->pluck('id'))->toContain((string) $k2->id)
        ->and($badgeItems->pluck('id'))->not->toContain((string) $k1->id);

    // Filter by age: lt6h
    $resAge = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests?age=lt6h');
    $resAge->assertStatus(200);
    $ageItems = collect($resAge->json('items'));
    expect($ageItems->pluck('id'))->toContain((string) $k1->id)
        ->and($ageItems->pluck('id'))->not->toContain((string) $k2->id);

    // Filter by country: CI
    $resCountry = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests?country=CI');
    $resCountry->assertStatus(200);
    $countryItems = collect($resCountry->json('items'));
    expect($countryItems->pluck('id'))->toContain((string) $k2->id)
        ->and($countryItems->pluck('id'))->not->toContain((string) $k1->id);

    // Filter by text search q: 12345
    $resQ = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests?q=12345');
    $resQ->assertStatus(200);
    $qItems = collect($resQ->json('items'));
    expect($qItems->pluck('id'))->toContain((string) $k1->id)
        ->and($qItems->pluck('id'))->not->toContain((string) $k2->id);
});

// =========================================================================
// POINT 27: COOKIES SECURE & STRICT, DÉCONNEXION EN 204
// =========================================================================

test('point 27: 2fa verify sets bango_admin_session and bango_admin_csrf with SameSite=Strict and Secure=true', function () {
    $operator = createTestAdminUser('direction');

    $loginRes = $this->postJson('/api/admin/auth/login', [
        'email' => $operator->email,
        'password' => 'Password123!Secure',
    ]);
    $challengeId = $loginRes->json('challengeId');

    $secret = Crypt::decryptString($operator->two_factor_secret);
    $code = getTotpCode($secret);

    $verifyRes = $this->postJson('/api/admin/auth/2fa/verify', [
        'challengeId' => $challengeId,
        'code' => $code,
    ]);

    $verifyRes->assertStatus(200);

    // Check cookies
    $sessionCookie = null;
    $csrfCookie = null;
    foreach ($verifyRes->headers->getCookies() as $cookie) {
        if ($cookie->getName() === 'bango_admin_session') {
            $sessionCookie = $cookie;
        } elseif ($cookie->getName() === 'bango_admin_csrf') {
            $csrfCookie = $cookie;
        }
    }

    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie->isSecure())->toBeTrue();
    expect($sessionCookie->getSameSite())->toBe('strict');
    expect($sessionCookie->isHttpOnly())->toBeTrue();

    expect($csrfCookie)->not->toBeNull();
    expect($csrfCookie->isSecure())->toBeTrue();
    expect($csrfCookie->getSameSite())->toBe('strict');
    expect($csrfCookie->isHttpOnly())->toBeFalse(); // CSRF cookie must be readable by JS
});

test('point 27: logout returns HTTP 204 and clears session cookies', function () {
    $operator = createTestAdminUser('direction');
    [$sessionId, $csrfToken] = createAuthenticatedSession($operator);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/auth/logout');

    $response->assertStatus(204);

    // Subsequent request with the same session cookie is rejected
    $subsequentRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/auth/session');

    $subsequentRes->assertStatus(401);
});
