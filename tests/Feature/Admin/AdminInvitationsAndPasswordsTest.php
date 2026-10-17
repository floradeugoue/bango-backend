<?php

use App\Models\Admin\AdminAccessRequest;
use App\Models\Admin\AdminInvitation;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $this->supportRole = Role::where('slug', 'support')->first();
    $this->directionRole = Role::where('slug', 'direction')->first();
});

it('returns the active password security policy', function () {
    $response = $this->getJson('/api/admin/auth/password/policy');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'min_length',
            'max_length',
            'require_uppercase',
            'require_lowercase',
            'require_numbers',
            'require_symbols',
            'description',
        ])
        ->assertJsonPath('min_length', 12)
        ->assertJsonPath('require_uppercase', true);
});

it('allows submitting internal access request only with @bango.app email and handles idempotently', function () {
    // 1. Rejette les adresses non @bango.app
    $invalidDomainResponse = $this->postJson('/api/admin/auth/access-requests', [
        'first_name' => 'Jean',
        'last_name' => 'Dupont',
        'email' => 'jean.dupont@gmail.com',
        'department' => 'Support Client',
        'requested_role' => 'support',
        'reason' => 'Demande d\'accès opérateur support',
    ]);
    $invalidDomainResponse->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    // 2. Accepte une adresse légitime @bango.app
    $validResponse = $this->postJson('/api/admin/auth/access-requests', [
        'first_name' => 'Alice',
        'last_name' => 'Support',
        'email' => 'alice.support@bango.app',
        'department' => 'Support Opérations',
        'requested_role' => 'support',
        'reason' => 'Nouvelle recrue support niveau 1',
    ]);
    $validResponse->assertStatus(201)
        ->assertJsonFragment(['message' => 'Votre demande d\'accès a bien été enregistrée et transmise à la direction pour validation.']);

    $this->assertDatabaseHas('admin_access_requests', [
        'email' => 'alice.support@bango.app',
        'status' => 'pending',
    ]);

    // 3. Soumission répétée (limitée à 3/h par RateLimiter)
    $duplicateResponse = $this->postJson('/api/admin/auth/access-requests', [
        'first_name' => 'Alice',
        'last_name' => 'Support',
        'email' => 'alice.support@bango.app',
        'department' => 'Support Opérations',
        'requested_role' => 'support',
        'reason' => 'Relance pour le support',
    ]);
    $duplicateResponse->assertStatus(201);

    expect(AdminAccessRequest::where('email', 'alice.support@bango.app')->count())->toBe(2);
});

it('displays minimal non-sensitive invitation status and rejects invalid or expired tokens', function () {
    $inviter = User::factory()->create(['role_id' => $this->directionRole->id]);

    // Invitation valide
    $invitation = AdminInvitation::create([
        'email' => 'marc.finance@bango.app',
        'token' => 'valid_secret_invitation_token_123',
        'role' => 'finance',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(2),
    ]);

    $response = $this->getJson('/api/admin/auth/invitations/valid_secret_invitation_token_123');
    $response->assertStatus(200)
        ->assertJsonStructure([
            'is_valid',
            'email',
            'role',
            'expires_at',
        ])
        ->assertJsonPath('is_valid', true)
        ->assertJsonPath('role', 'finance');

    // Token inconnu
    $unknownResponse = $this->getJson('/api/admin/auth/invitations/nonexistent_token');
    $unknownResponse->assertStatus(404);

    // Token expiré
    $invitation->update(['expires_at' => now()->subDay()]);
    $expiredResponse = $this->getJson('/api/admin/auth/invitations/valid_secret_invitation_token_123');
    $expiredResponse->assertStatus(404)
        ->assertJsonPath('is_valid', false);
});

it('activates operator account atomically upon invitation acceptance and prevents reuse', function () {
    $inviter = User::factory()->create(['role_id' => $this->directionRole->id]);

    $invitation = AdminInvitation::create([
        'email' => 'claire.modo@bango.app',
        'token' => 'modo_invitation_token_abc',
        'role' => 'moderation',
        'invited_by' => $inviter->id,
        'expires_at' => now()->addDays(2),
    ]);

    $acceptResponse = $this->postJson('/api/admin/auth/invitations/modo_invitation_token_abc/accept', [
        'name' => 'Claire Modo',
        'password' => 'Password123!Secure2026',
        'password_confirmation' => 'Password123!Secure2026',
    ]);

    $acceptResponse->assertStatus(200)
        ->assertJsonFragment(['message' => 'Votre compte opérateur a été activé avec succès. Vous pouvez désormais vous connecter.']);

    // Vérifie la création du compte opérateur avec le rôle défini par l'invitation
    $user = User::where('email', 'claire.modo@bango.app')->first();
    expect($user)->not->toBeNull();
    expect($user->role->slug)->toBe('moderation');
    expect($user->status)->toBe('active');
    expect(Hash::check('Password123!Secure2026', $user->password))->toBeTrue();

    // Vérifie l'état de l'invitation
    $invitation->refresh();
    expect($invitation->accepted_at)->not->toBeNull();

    // Tentative de réutilisation du même token -> Rejetée 400
    $reuseResponse = $this->postJson('/api/admin/auth/invitations/modo_invitation_token_abc/accept', [
        'name' => 'Claire Modo',
        'password' => 'Password123!Secure2026',
        'password_confirmation' => 'Password123!Secure2026',
    ]);
    $reuseResponse->assertStatus(400);
});

it('handles forgot password and resets password securely while invalidating prior sessions', function () {
    $operator = User::factory()->create([
        'email' => 'target.operator@bango.app',
        'password' => Hash::make('OldPassword123!Secure'),
        'role_id' => $this->supportRole->id,
        'status' => 'active',
    ]);

    // Crée une session active préalable
    $priorSession = AdminSession::create([
        'id' => bin2hex(random_bytes(32)),
        'user_id' => $operator->id,
        'csrf_token' => bin2hex(random_bytes(32)),
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // 1. Demande de mot de passe oublié (réponse neutre anti-énumération)
    $forgotResponse = $this->postJson('/api/admin/auth/password/forgot', [
        'email' => 'target.operator@bango.app',
    ]);
    $forgotResponse->assertStatus(200)
        ->assertJsonFragment(['message' => 'Si cette adresse correspond à un compte opérateur actif, les instructions de réinitialisation ont été transmises.']);

    // Une adresse inexistante reçoit exactement la même réponse (anti-énumération)
    $fakeForgotResponse = $this->postJson('/api/admin/auth/password/forgot', [
        'email' => 'unknown@bango.app',
    ]);
    $fakeForgotResponse->assertStatus(200)
        ->assertJsonFragment(['message' => 'Si cette adresse correspond à un compte opérateur actif, les instructions de réinitialisation ont été transmises.']);

    // Vérifie qu'un jeton de réinitialisation existe dans password_reset_tokens
    $resetRecord = DB::table('password_reset_tokens')
        ->where('email', 'target.operator@bango.app')
        ->first();
    expect($resetRecord)->not->toBeNull();

    // 2. Consultation du token
    $showResponse = $this->getJson("/api/admin/reset/{$resetRecord->token}");
    $showResponse->assertStatus(200)
        ->assertJsonFragment(['is_valid' => true]);

    // 3. Soumission du nouveau mot de passe
    $resetSubmitResponse = $this->postJson("/api/admin/reset/{$resetRecord->token}", [
        'token' => $resetRecord->token,
        'password' => 'NewPassword456!Secure2026',
        'password_confirmation' => 'NewPassword456!Secure2026',
    ]);
    $resetSubmitResponse->assertStatus(200)
        ->assertJsonFragment(['message' => 'Votre mot de passe a été réinitialisé avec succès. Toutes vos sessions ont été déconnectées par mesure de sécurité.']);

    // Le mot de passe a bien changé
    $operator->refresh();
    expect(Hash::check('NewPassword456!Secure2026', $operator->password))->toBeTrue();

    // Les sessions antérieures ont été révoquées pour empêcher le maintien d'accès non autorisé
    $priorSession->refresh();
    expect($priorSession->isRevoked())->toBeTrue();

    // Le jeton de réinitialisation a été consommé (usage unique)
    $reuseResetResponse = $this->postJson("/api/admin/reset/{$resetRecord->token}", [
        'token' => $resetRecord->token,
        'password' => 'AnotherPassword789!Secure',
        'password_confirmation' => 'AnotherPassword789!Secure',
    ]);
    $reuseResetResponse->assertStatus(400);
});
