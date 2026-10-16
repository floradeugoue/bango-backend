<?php

use App\Models\Legal\LegalDocument;
use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    // Définition de la version mandatory des CGU
    LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'CGU v1',
        'content_markdown' => 'Contenu v1',
        'effective_date' => now()->subYear(),
        'is_published' => true,
        'is_mandatory' => false,
    ]);

    LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '2.0.0',
        'locale' => 'fr',
        'title' => 'CGU v2 Obligatoire',
        'content_markdown' => 'Contenu v2 officiel',
        'effective_date' => now()->subDay(),
        'is_published' => true,
        'is_mandatory' => true,
    ]);
});

test('unauthenticated user cannot accept terms', function () {
    $this->postJson('/api/legal/accept-terms', [])
        ->assertStatus(401);
});

test('authenticated user can accept the current mandatory terms version', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/legal/accept-terms', [
        'locale' => 'fr',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('version', '2.0.0')
        ->assertJsonPath('document_slug', 'terms-of-service')
        ->assertJsonPath('is_compliant', true);

    $this->assertDatabaseHas('terms_acceptances', [
        'user_id' => $this->user->id,
        'version' => '2.0.0',
        'document_slug' => 'terms-of-service',
    ]);

    $this->user->refresh();
    expect($this->user->accepted_terms_version)->toBe('2.0.0')
        ->and($this->user->terms_accepted_at)->not->toBeNull();
});

test('terms acceptance is idempotent and does not create duplicate entries', function () {
    Passport::actingAs($this->user);

    // Première acceptation
    $this->postJson('/api/legal/accept-terms', ['version' => '2.0.0'])
        ->assertOk();

    expect(TermsAcceptance::where('user_id', $this->user->id)->count())->toBe(1);

    // Seconde tentative identique
    $secondResponse = $this->postJson('/api/legal/accept-terms', ['version' => '2.0.0']);
    $secondResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('version', '2.0.0');

    expect(TermsAcceptance::where('user_id', $this->user->id)->count())->toBe(1);
});

test('rejects attempt to accept an outdated terms version', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/legal/accept-terms', [
        'version' => '1.0.0', // Ancienne version alors que 2.0.0 est obligatoire
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'OUTDATED_TERMS_VERSION')
        ->assertJsonPath('required_version', '2.0.0')
        ->assertJsonPath('provided_version', '1.0.0');

    // L'utilisateur n'est pas passé en v1.0.0
    $this->user->refresh();
    expect($this->user->accepted_terms_version)->toBeNull();
});

test('ensure terms accepted middleware blocks user until mandatory terms are accepted', function () {
    // Enregistrement d'une route protégée de test avec le middleware terms.accepted
    Route::middleware(['auth:api', 'terms.accepted'])->get('/api/test-protected-terms', function () {
        return response()->json(['secret' => 'access_granted']);
    });

    Passport::actingAs($this->user);

    // 1. Utilisateur n'ayant pas encore accepté les CGU -> 403 Forbidden
    $response = $this->getJson('/api/test-protected-terms');
    $response->assertStatus(403)
        ->assertJsonPath('error', 'TERMS_ACCEPTANCE_REQUIRED')
        ->assertJsonPath('required_version', '2.0.0');

    // 2. Les routes exemptées (ex: consultation légale ou acceptation) restent accessibles
    $this->getJson('/api/legal/documents/terms-of-service')
        ->assertOk();

    // 3. Acceptation effective
    $this->postJson('/api/legal/accept-terms', ['version' => '2.0.0'])
        ->assertOk();

    // 4. L'accès à la route protégée est désormais déverrouillé
    $this->getJson('/api/test-protected-terms')
        ->assertOk()
        ->assertJsonPath('secret', 'access_granted');
});
