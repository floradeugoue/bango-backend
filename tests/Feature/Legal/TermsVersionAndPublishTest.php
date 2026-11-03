<?php

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Legal\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);

    $this->directionRole = Role::firstOrCreate(['slug' => 'direction'], ['name' => 'Direction']);

    $this->adminUser = User::factory()->create([
        'email' => 'direction@bango.app',
        'role_id' => $this->directionRole->id,
        'status' => 'active',
    ]);

    $this->rawSessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    $this->adminSession = AdminSession::create([
        'id' => hash('sha256', $this->rawSessionId),
        'user_id' => $this->adminUser->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(8),
    ]);
});

test('admin can publish legal document with effective_date without class Carbon not found 500 error', function () {
    $response = $this->withHeader('X-Bango-Admin-Session', $this->rawSessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson('/api/admin/v1/settings/legal/terms-of-service/publish', [
            'version' => '3.1.0',
            'title' => 'Conditions Générales d\'Utilisation v3.1',
            'content_markdown' => '# CGU 3.1\n\nArticle 8 : un agent peut mettre en avant les biens qu\'il gère.',
            'effective_date' => '2026-11-03 10:00:00',
            'is_mandatory' => true,
            'locale' => 'fr',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.version', '3.1.0')
        ->assertJsonPath('data.slug', 'terms-of-service');

    $this->assertDatabaseHas('legal_documents', [
        'slug' => 'terms-of-service',
        'version' => '3.1.0',
        'is_mandatory' => true,
    ]);
});

test('profile route returns accepted_terms_version and terms_accepted_at', function () {
    $now = Carbon::parse('2026-11-03 12:34:56');

    $user = User::factory()->create([
        'accepted_terms_version' => '3.1.0',
        'terms_accepted_at' => $now,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/profile');

    $response->assertOk()
        ->assertJsonPath('data.accepted_terms_version', '3.1.0')
        ->assertJsonPath('data.acceptedTermsVersion', '3.1.0')
        ->assertJsonPath('data.terms_accepted_at', $now->toIso8601String())
        ->assertJsonPath('data.termsAcceptedAt', $now->toIso8601String());
});

test('profile route is accessible even if user has not accepted latest mandatory terms', function () {
    LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '3.1.0',
        'locale' => 'fr',
        'title' => 'CGU v3.1',
        'content_markdown' => 'Contenu v3.1',
        'effective_date' => now()->subDay(),
        'is_published' => true,
        'is_mandatory' => true,
    ]);

    $user = User::factory()->create([
        'accepted_terms_version' => '3.0.0', // Version obsolète
        'terms_accepted_at' => now()->subYear(),
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/profile');

    $response->assertOk()
        ->assertJsonPath('data.accepted_terms_version', '3.0.0');
});

test('terms.accepted middleware blocks write action when mandatory terms are not accepted and allows after acceptance', function () {
    LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '3.1.0',
        'locale' => 'fr',
        'title' => 'CGU v3.1',
        'content_markdown' => 'Contenu v3.1',
        'effective_date' => now()->subDay(),
        'is_published' => true,
        'is_mandatory' => true,
    ]);

    $user = User::factory()->create([
        'accepted_terms_version' => null,
        'terms_accepted_at' => null,
    ]);

    Passport::actingAs($user);

    // 1. Tentative de démarrer une conversation (route protégée par terms.accepted) -> 403
    $blockedResponse = $this->postJson('/api/messages/threads', [
        'recipient_id' => 999,
        'type' => 'direct',
    ]);

    $blockedResponse->assertStatus(403)
        ->assertJsonPath('error', 'TERMS_ACCEPTANCE_REQUIRED')
        ->assertJsonPath('required_version', '3.1.0');

    // 2. Acceptation de la version 3.1.0 via la route légale
    $acceptResponse = $this->postJson('/api/legal/accept-terms', [
        'version' => '3.1.0',
    ]);
    $acceptResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('version', '3.1.0');

    // 3. Après acceptation, l'utilisateur n'est plus bloqué par terms.accepted
    $afterAcceptResponse = $this->postJson('/api/messages/threads', [
        'recipient_id' => 999,
        'type' => 'direct',
    ]);

    expect($afterAcceptResponse->status())->not->toBe(403);
});
