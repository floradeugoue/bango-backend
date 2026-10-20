<?php

namespace Tests\Feature\Security;

use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Auth\TotpService;
use App\Services\Boost\CreditWalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'SecurityTest', '--no-interaction' => true]);
    $this->seed(RoleSeeder::class);
});

function createAdminSessionHelper(User $operator): array
{
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

    return [$sessionId, $csrfToken, $session];
}

/* ==========================================================================
 * POINT 1 : LE JETON MOBILE NE DOIT PAS OUVRIR LE BACK-OFFICE
 * ========================================================================== */

it('P1 - rejects passport bearer tokens on back-office routes without an active admin session', function () {
    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $adminUser = User::factory()->create([
        'email' => 'admin.passport@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    // L'administrateur s'authentifie via Passport (jeton mobile/API)
    Passport::actingAs($adminUser);

    // Tentative d'accès à la liste des utilisateurs du back-office
    $responseUsers = $this->getJson('/api/admin/users');
    $responseUsers->assertStatus(401)
        ->assertJson([
            'message' => 'Non authentifié. Session administrative requise.',
        ]);

    // Tentative d'accès à une route de mise à jour back-office
    $responseNotifications = $this->postJson('/api/admin/notifications/read-all');
    $responseNotifications->assertStatus(401)
        ->assertJson([
            'message' => 'Non authentifié. Session administrative requise.',
        ]);
});

it('P1 - allows back-office access only with a valid verified admin session cookie', function () {
    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $adminUser = User::factory()->create([
        'email' => 'admin.session@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    [$sessionId, $csrfToken] = createAdminSessionHelper($adminUser);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/users');

    $response->assertStatus(200);
});

/* ==========================================================================
 * POINT 2 : COMPTES OPÉRATEURS CONDITIONNÉS ET COMMANDE ARTISAN ADMIN:CREATE
 * ========================================================================== */

it('P2 - creates admin via artisan admin:create with masked strong password', function () {
    $this->artisan('admin:create', [
        '--name' => 'Super Administrateur',
        '--email' => 'new.admin@bango.app',
        '--role' => 'admin',
    ])
        ->expectsQuestion('Mot de passe (masqué)', 'ComplexPassword123!@#')
        ->expectsQuestion('Confirmation du mot de passe', 'ComplexPassword123!@#')
        ->assertSuccessful();

    $created = User::where('email', 'new.admin@bango.app')->first();
    expect($created)->not->toBeNull()
        ->and($created->role->slug)->toBe('admin')
        ->and(Hash::check('ComplexPassword123!@#', $created->password))->toBeTrue();
});

it('P2 - rejects weak passwords in artisan admin:create', function () {
    $this->artisan('admin:create', [
        '--name' => 'Weak Admin',
        '--email' => 'weak.admin@bango.app',
        '--role' => 'admin',
    ])
        ->expectsQuestion('Mot de passe (masqué)', 'weak')
        ->expectsQuestion('Confirmation du mot de passe', 'weak')
        ->assertFailed();

    expect(User::where('email', 'weak.admin@bango.app')->exists())->toBeFalse();
});

/* ==========================================================================
 * POINT 3 : RACCOURCIS DE TEST (123456 ET DEBUG_TOKEN) RETIRÉS
 * ========================================================================== */

it('P3 - rejects 123456 fallback and requires real cryptographic TOTP', function () {
    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'operator.totp@bango.app',
        'password' => Hash::make('StrongPassword123!'),
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $loginResponse = $this->postJson('/api/admin/auth/login', [
        'email' => 'operator.totp@bango.app',
        'password' => 'StrongPassword123!',
    ]);

    $challengeToken = $loginResponse->json('challenge_token');

    // 1. Rejet strict du code magique 123456
    $fakeResponse = $this->postJson('/api/admin/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => '123456',
    ]);
    $fakeResponse->assertStatus(401);

    // 2. Succès uniquement avec un vrai secret TOTP et code RFC 6238 calculé
    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();
    $operator->two_factor_secret = Crypt::encryptString($secret);
    $operator->save();

    $validCode = $totpService->calculateTotpForStep($secret, (int) floor(time() / 30));

    $validResponse = $this->postJson('/api/admin/auth/2fa/verify', [
        'challenge_token' => $challengeToken,
        'code' => $validCode,
    ]);
    $validResponse->assertStatus(200);
});

it('P3 - does not return debug_token in forgot password response', function () {
    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'operator.reset@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $response = $this->postJson('/api/admin/auth/password/forgot', [
        'email' => 'operator.reset@bango.app',
    ]);

    $response->assertStatus(200);
    expect($response->json('debug_token'))->toBeNull();
});

/* ==========================================================================
 * POINT 4 : SÉCURISATION DES RÔLES & PROTECTION CONTRE L'ESCALADE DE PRIVILÈGES
 * ========================================================================== */

it('P4 - forbids modifying the slug of system roles', function () {
    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $adminUser = User::factory()->create([
        'email' => 'admin.slug@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    [$sessionId, $csrfToken] = createAdminSessionHelper($adminUser);

    $userRole = Role::where('slug', 'user')->firstOrFail();

    // Tentative d'escalade : renommer user en admin
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->putJson("/api/admin/roles/{$userRole->id}", [
            'name' => 'Utilisateur modifié',
            'slug' => 'admin_usurped',
        ]);

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'SYSTEM_ROLE_SLUG_IMMUTABLE',
        ]);

    expect($userRole->fresh()->slug)->toBe('user');
});

it('P4 - forbids self-role modification and demoting the last active administrator', function () {
    // Nettoie les autres admins pour s'assurer qu'il n'en reste qu'un seul
    User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->delete();

    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $soleAdmin = User::factory()->create([
        'email' => 'sole.admin@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $userRole = Role::where('slug', 'user')->firstOrFail();

    [$sessionId, $csrfToken] = createAdminSessionHelper($soleAdmin);

    // 1. Interdiction de modifier son propre rôle (auto-assignation)
    $selfResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->patchJson("/api/admin/users/{$soleAdmin->id}/role", [
            'role_id' => $userRole->id,
        ]);

    $selfResponse->assertStatus(403)
        ->assertJson([
            'code' => 'SELF_ROLE_CHANGE_FORBIDDEN',
        ]);

    // Crée un second opérateur admin pour tester la rétrogradation du dernier admin
    $secondAdmin = User::factory()->create([
        'email' => 'second.admin@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    // Rétrogradation du premier admin par le second admin -> autorisé car il reste 1 admin
    [$secondSessionId, $secondCsrf] = createAdminSessionHelper($secondAdmin);

    $demoteFirst = $this->withUnencryptedCookie('bango_admin_session', $secondSessionId)
        ->withHeader('X-CSRF-TOKEN', $secondCsrf)
        ->patchJson("/api/admin/users/{$soleAdmin->id}/role", [
            'role_id' => $userRole->id,
            'require_approval' => false,
        ]);
    $demoteFirst->assertStatus(200);

    // Tentative de rétrograder le secondAdmin par un autre utilisateur (ou si le secondAdmin est le dernier)
    // Crée un opérateur modérateur pour tenter de rétrograder le secondAdmin
    $modRole = Role::where('slug', 'moderation')->firstOrFail();
    $modUser = User::factory()->create([
        'email' => 'mod.attacker@bango.app',
        'role_id' => $modRole->id,
        'status' => 'active',
    ]);

    [$modSessionId, $modCsrf] = createAdminSessionHelper($modUser);

    // Un non-admin ne peut pas rétrograder le dernier admin ni attribuer admin/direction
    $demoteLast = $this->withUnencryptedCookie('bango_admin_session', $modSessionId)
        ->withHeader('X-CSRF-TOKEN', $modCsrf)
        ->patchJson("/api/admin/users/{$secondAdmin->id}/role", [
            'role_id' => $userRole->id,
        ]);

    $demoteLast->assertStatus(403);
    expect($secondAdmin->fresh()->role->slug)->toBe('admin');
});

it('P4 - forbids non-admins from assigning admin or direction roles', function () {
    $modRole = Role::where('slug', 'moderation')->firstOrFail();
    $modRole->update(['permissions' => array_merge($modRole->permissions ?? [], ['operators:manage'])]);
    $modUser = User::factory()->create([
        'email' => 'mod.operator@bango.app',
        'role_id' => $modRole->id,
        'status' => 'active',
    ]);

    $targetUser = User::factory()->create([
        'email' => 'target.user@bango.app',
        'role_id' => Role::where('slug', 'user')->value('id'),
    ]);

    $adminRole = Role::where('slug', 'admin')->firstOrFail();

    [$sessionId, $csrfToken] = createAdminSessionHelper($modUser);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->patchJson("/api/admin/users/{$targetUser->id}/role", [
            'role_id' => $adminRole->id,
        ]);

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'ADMIN_PRIVILEGE_REQUIRED',
        ]);

    expect($targetUser->fresh()->role->slug)->toBe('user');
});

/* ==========================================================================
 * POINT 5 : CRÉDIT GRATUIT FORCLOS ET RÉSERVÉ AU BACK-OFFICE AVEC AUDIT
 * ========================================================================== */

it('P5 - strictly forbids direct wallet deposits by standard users', function () {
    $user = User::factory()->create(['email' => 'free.credit.seeker@bango.app']);
    Passport::actingAs($user);

    $response = $this->postJson('/api/boost/wallet/deposit', [
        'amount' => 1000000,
        'description' => 'Tentative crédit gratuit 1M',
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'DIRECT_DEPOSIT_FORBIDDEN',
        ]);

    $walletService = app(CreditWalletService::class);
    expect($walletService->getBalance($user))->toBe(0.0);
});

it('P5 - allows direct wallet deposit exclusively to back-office operators with required reason and audit log', function () {
    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'finance.operator@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $targetUser = User::factory()->create(['email' => 'client.beneficiary@bango.app']);

    [$sessionId, $csrfToken] = createAdminSessionHelper($operator);

    // 1. Échec si motif manquant
    $failResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/credits/wallets/{$targetUser->id}/deposit", [
            'amount' => 50000,
        ]);
    $failResponse->assertStatus(422);

    // 2. Succès avec motif administratif
    $successResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/credits/wallets/{$targetUser->id}/deposit", [
            'amount' => 50000,
            'reason' => 'Compensation suite à incident technique billetterie',
        ]);

    $successResponse->assertStatus(201)
        ->assertJsonPath('data.amount', 50000);

    $walletService = app(CreditWalletService::class);
    expect($walletService->getBalance($targetUser))->toBe(50000.0);

    // 3. Vérifie l'enregistrement dans le journal d'audit chaîné
    $audit = AdminAuditLog::where('event', 'finance.wallet_manual_credit')
        ->where('target_id', (string) $targetUser->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and((float) $audit->context['amount'])->toBe(50000.0)
        ->and($audit->context['reason'])->toBe('Compensation suite à incident technique billetterie');
});
