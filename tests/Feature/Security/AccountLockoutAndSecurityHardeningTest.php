<?php

use App\Http\Controllers\Admin\Auth\AdminInvitationController;
use App\Http\Controllers\Admin\Auth\AdminPasswordResetController;
use App\Http\Requests\Admin\Auth\AdminPasswordForgotRequest;
use App\Models\Admin\AdminInvitation;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Admin\AdminAuthService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'SecurityHardeningTest', '--no-interaction' => true]);
    $this->seed(RoleSeeder::class);

    $this->adminRole = Role::where('slug', 'admin')->firstOrFail();
    $this->supportRole = Role::where('slug', 'support')->firstOrFail();
    $this->userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);

    Cache::flush();
});

/* ==========================================================================
 * POINT 1 : 2FA ATTAQUABLE EN BOUCLE -> VERROU PAR COMPTE APRÈS 5 ÉCHECS (423)
 * ========================================================================== */

it('P1 - locks operator account for 30 minutes after 5 failed 2FA attempts across challenges and returns HTTP 423', function () {
    $operator = User::factory()->create([
        'email' => 'op.totp@bango.app',
        'password' => Hash::make('OperatorPassword123!'),
        'role_id' => $this->supportRole->id,
        'status' => 'active',
        'two_factor_secret' => 'JBSWY3DPEHPK3PXP', // Valid Base32 TOTP secret
    ]);

    // Challenge 1 : 2 tentatives échouées
    $loginResponse1 = $this->postJson('/api/admin/auth/login', [
        'email' => 'op.totp@bango.app',
        'password' => 'OperatorPassword123!',
    ])->assertStatus(200);

    $token1 = $loginResponse1->json('challenge_token');

    $this->postJson('/api/admin/auth/2fa/verify', ['challenge_token' => $token1, 'code' => '000001'])
        ->assertStatus(422)
        ->assertJsonPath('remaining_attempts', 4);

    $this->postJson('/api/admin/auth/2fa/verify', ['challenge_token' => $token1, 'code' => '000002'])
        ->assertStatus(422)
        ->assertJsonPath('remaining_attempts', 3);

    // L'attaquant relance /auth/login pour obtenir un nouveau challenge et contourner la limite du premier
    $loginResponse2 = $this->postJson('/api/admin/auth/login', [
        'email' => 'op.totp@bango.app',
        'password' => 'OperatorPassword123!',
    ])->assertStatus(200);

    $token2 = $loginResponse2->json('challenge_token');
    expect($token2)->not->toBe($token1);

    // Tentatives 3 et 4 sur le challenge 2
    $this->postJson('/api/admin/auth/2fa/verify', ['challenge_token' => $token2, 'code' => '000003'])
        ->assertStatus(422)
        ->assertJsonPath('remaining_attempts', 2);

    $this->postJson('/api/admin/auth/2fa/verify', ['challenge_token' => $token2, 'code' => '000004'])
        ->assertStatus(422)
        ->assertJsonPath('remaining_attempts', 1);

    // L'attaquant relance encore /auth/login pour tenter le 5ème code
    $loginResponse3 = $this->postJson('/api/admin/auth/login', [
        'email' => 'op.totp@bango.app',
        'password' => 'OperatorPassword123!',
    ])->assertStatus(200);

    $token3 = $loginResponse3->json('challenge_token');

    // 5ème tentative échouée toutes sessions/challenges confondues -> VERROU DU COMPTE
    $lockResponse = $this->postJson('/api/admin/auth/2fa/verify', ['challenge_token' => $token3, 'code' => '000005']);
    $lockResponse->assertStatus(423)
        ->assertJsonPath('code', 'account_locked')
        ->assertJsonStructure(['message', 'code', 'locked_until']);

    // Le compte en base de données doit avoir locked_until positionné dans le futur (~30 min)
    $operator->refresh();
    expect($operator->locked_until)->not->toBeNull();
    expect($operator->locked_until->isFuture())->toBeTrue();
    expect($operator->isLocked())->toBeTrue();

    // Toute nouvelle tentative de connexion retourne immédiatement HTTP 423
    $this->postJson('/api/admin/auth/login', [
        'email' => 'op.totp@bango.app',
        'password' => 'OperatorPassword123!',
    ])->assertStatus(423)
        ->assertJsonPath('code', 'account_locked');

    // Toute tentative 2FA résiduelle sur les challenges est également rejetée en 423
    $this->postJson('/api/admin/auth/2fa/verify', ['challenge_token' => $token3, 'code' => '000006'])
        ->assertStatus(423)
        ->assertJsonPath('code', 'account_locked');
});

/* ==========================================================================
 * POINT 2 : COMPTE BLOQUÉ ET SUPPRIMÉ REFUSÉ PARTOUT ET SESSIONS RÉVOQUÉES
 * ========================================================================== */

it('P2 - rejects blocked and deleted operators at login and in EnsureAdminSession middleware', function () {
    // 1. Opérateur avec status 'blocked'
    $blockedOperator = User::factory()->create([
        'email' => 'blocked.op@bango.app',
        'password' => Hash::make('Password123!'),
        'role_id' => $this->supportRole->id,
        'status' => 'blocked',
    ]);

    $this->postJson('/api/admin/auth/login', [
        'email' => 'blocked.op@bango.app',
        'password' => 'Password123!',
    ])->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_BLOCKED');

    // 2. Opérateur supprimé (Soft Delete)
    $deletedOperator = User::factory()->create([
        'email' => 'deleted.op@bango.app',
        'password' => Hash::make('Password123!'),
        'role_id' => $this->supportRole->id,
        'status' => 'active',
    ]);
    $deletedOperator->delete();

    $this->postJson('/api/admin/auth/login', [
        'email' => 'deleted.op@bango.app',
        'password' => 'Password123!',
    ])->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_DELETED');

    // 3. Opérateur avec session existante dont le statut bascule à 'blocked'
    $activeOperator = User::factory()->create([
        'email' => 'active.op@bango.app',
        'role_id' => $this->supportRole->id,
        'status' => 'active',
    ]);

    $rawSessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => hash('sha256', $rawSessionId),
        'user_id' => $activeOperator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // La session passe lorsqu'actif
    $this->withHeaders([
        'X-Bango-Admin-Session' => $rawSessionId,
        'X-CSRF-Token' => $csrfToken,
    ])->getJson('/api/admin/notifications')
        ->assertStatus(200);

    // L'opérateur est basculé à 'blocked'
    $activeOperator->update(['status' => 'blocked']);

    // Le middleware bloque immédiatement la session active
    $this->withHeaders([
        'X-Bango-Admin-Session' => $rawSessionId,
        'X-CSRF-Token' => $csrfToken,
    ])->getJson('/api/admin/notifications')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_BLOCKED');
});

it('P2 - revokes all active admin_sessions in database when user is blocked or suspended via admin routes', function () {
    $admin = User::factory()->create([
        'email' => 'superadmin@bango.app',
        'role_id' => $this->adminRole->id,
        'status' => 'active',
    ]);

    $targetOperator = User::factory()->create([
        'email' => 'target.op@bango.app',
        'role_id' => $this->supportRole->id,
        'status' => 'active',
    ]);

    // Sessions actives de l'opérateur cible
    $targetSession = AdminSession::create([
        'id' => hash('sha256', 'target_session_secret_1'),
        'user_id' => $targetOperator->id,
        'csrf_token' => 'csrf_1',
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // Session de l'admin
    $adminSessionId = bin2hex(random_bytes(32));
    $adminCsrf = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => hash('sha256', $adminSessionId),
        'user_id' => $admin->id,
        'csrf_token' => $adminCsrf,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // Blocage via la route admin dédiée
    $this->withHeaders([
        'X-Bango-Admin-Session' => $adminSessionId,
        'X-CSRF-Token' => $adminCsrf,
    ])->postJson("/api/admin/users/{$targetOperator->id}/block")
        ->assertStatus(200)
        ->assertJsonPath('status', 'blocked');

    // La session admin de l'opérateur cible a été révoquée
    $targetSession->refresh();
    expect($targetSession->isRevoked())->toBeTrue();
    expect($targetSession->revoked_at)->not->toBeNull();
});

/* ==========================================================================
 * POINT 3 : FICHE UTILISATEUR SANS GARDE-FOU (STATUS ET IS_VERIFIED VERROUILLÉS)
 * ========================================================================== */

it('P3 - rejects direct status and is_verified modification on PATCH /admin/users/{id}', function () {
    $admin = User::factory()->create([
        'email' => 'admin.guardrails@bango.app',
        'role_id' => $this->adminRole->id,
        'status' => 'active',
    ]);

    $adminSessionId = bin2hex(random_bytes(32));
    $adminCsrf = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => hash('sha256', $adminSessionId),
        'user_id' => $admin->id,
        'csrf_token' => $adminCsrf,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $targetUser = User::factory()->create([
        'role_id' => $this->userRole->id,
        'status' => 'active',
        'is_verified' => false,
    ]);

    // 1. Tenter d'envoyer un statut quelconque doit être strictement rejeté (422)
    $this->withHeaders([
        'X-Bango-Admin-Session' => $adminSessionId,
        'X-CSRF-Token' => $adminCsrf,
    ])->patchJson("/api/admin/users/{$targetUser->id}", [
        'status' => 'nimportequoi',
    ])->assertStatus(422)
        ->assertJsonPath('code', 'STATUS_CHANGE_RESTRICTED');

    $targetUser->refresh();
    expect($targetUser->status)->toBe('active');

    // 2. Tenter de basculer is_verified doit être strictement rejeté (422)
    $this->withHeaders([
        'X-Bango-Admin-Session' => $adminSessionId,
        'X-CSRF-Token' => $adminCsrf,
    ])->patchJson("/api/admin/users/{$targetUser->id}", [
        'is_verified' => true,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'KYC_VERIFICATION_RESTRICTED');

    $targetUser->refresh();
    expect($targetUser->is_verified)->toBeFalse();

    // 3. Les modifications autorisées (ex: display_name) fonctionnent normalement
    $this->withHeaders([
        'X-Bango-Admin-Session' => $adminSessionId,
        'X-CSRF-Token' => $adminCsrf,
    ])->patchJson("/api/admin/users/{$targetUser->id}", [
        'display_name' => 'Nouveau Nom Conforme',
    ])->assertStatus(200)
        ->assertJsonPath('user.display_name', 'Nouveau Nom Conforme');
});

/* ==========================================================================
 * POINT 4 : SECRETS HACHÉS EN SHA-256 EN BASE (SESSIONS, INVITATIONS, RESETS)
 * ========================================================================== */

it('P4 - stores SHA-256 hashes in database for admin sessions, reset tokens, and invitation tokens', function () {
    // 1. Admin Session ID : La base ne stocke que le hash SHA-256
    $operator = User::factory()->create([
        'email' => 'op.hash@bango.app',
        'role_id' => $this->supportRole->id,
        'status' => 'active',
        'password' => Hash::make('Secret123!Safe'),
    ]);

    $authService = app(AdminAuthService::class);
    $request = Request::create('/api/admin/auth/2fa', 'POST', [], [], [], [
        'REMOTE_ADDR' => '127.0.0.1',
    ]);

    $loginResponse = $authService->establishSession($operator, $request);
    $cookies = $loginResponse->headers->getCookies();

    $sessionCookie = null;
    foreach ($cookies as $c) {
        if ($c->getName() === 'bango_admin_session') {
            $sessionCookie = $c->getValue();
        }
    }

    expect($sessionCookie)->not->toBeNull();
    // Le cookie est le token brut (hex de 64 chars)
    expect(strlen($sessionCookie))->toBe(64);

    // Vérification que la valeur brute du cookie N'EST PAS en base de données
    expect(AdminSession::find($sessionCookie))->toBeNull();

    // Mais son hash SHA-256 EST la clé primaire en base de données
    $hashedSessionId = hash('sha256', $sessionCookie);
    $sessionInDb = AdminSession::find($hashedSessionId);
    expect($sessionInDb)->not->toBeNull();
    expect($sessionInDb->user_id)->toBe($operator->id);

    // 2. Password Reset Token : Stocké sous forme hachée SHA-256
    $resetRequest = new AdminPasswordForgotRequest;
    $resetRequest->merge(['email' => 'op.hash@bango.app']);

    $resetController = app(AdminPasswordResetController::class);
    $resetController->forgot($resetRequest);

    $resetRow = DB::table('password_reset_tokens')->where('email', 'op.hash@bango.app')->first();
    expect($resetRow)->not->toBeNull();
    // Le token stocké est un hash SHA-256 de 64 caractères
    expect(strlen($resetRow->token))->toBe(64);

    // La consultation via showReset avec ce hash fonctionne
    $showRes = $resetController->showReset($resetRow->token);
    expect($showRes->getStatusCode())->toBe(200);

    // 3. Admin Invitation Token : Stocké sous forme hachée SHA-256
    $rawInvitationToken = 'my_secret_invitation_key_999';
    $invitation = AdminInvitation::create([
        'email' => 'new.recrue@bango.app',
        'token' => $rawInvitationToken,
        'role' => 'support',
        'invited_by' => $operator->id,
        'expires_at' => now()->addDays(3),
    ]);

    // En base de données, la colonne token contient le hash SHA-256 et non la chaîne brute
    $invitationInDb = DB::table('admin_invitations')->where('id', $invitation->id)->first();
    expect($invitationInDb->token)->toBe(hash('sha256', $rawInvitationToken));
    expect($invitationInDb->token)->not->toBe($rawInvitationToken);

    // La vérification et acceptation via le token brut d'invitation fonctionnent
    $invitationController = app(AdminInvitationController::class);
    $invitationShowRes = $invitationController->show($rawInvitationToken);
    expect($invitationShowRes->getStatusCode())->toBe(200);
});
