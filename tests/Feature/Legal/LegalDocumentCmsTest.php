<?php

use App\Models\Legal\LegalDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = User::factory()->create();
});

test('public user can retrieve an active published legal document in default locale', function () {
    $doc = LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'Conditions Générales d\'Utilisation BANGO',
        'content_markdown' => '# CGU BANGO\n\nBienvenue sur BANGO.',
        'effective_date' => now()->subDay(),
        'is_published' => true,
        'published_at' => now()->subDay(),
        'is_mandatory' => true,
        'created_by' => $this->author->id,
    ]);

    $response = $this->getJson('/api/legal/documents/terms-of-service');

    $response->assertOk()
        ->assertJsonPath('data.slug', 'terms-of-service')
        ->assertJsonPath('data.version', '1.0.0')
        ->assertJsonPath('data.locale', 'fr')
        ->assertJsonPath('data.title', 'Conditions Générales d\'Utilisation BANGO')
        ->assertJsonPath('data.content_markdown', '# CGU BANGO\n\nBienvenue sur BANGO.')
        ->assertJsonPath('data.is_mandatory', true);
});

test('public user can request a document in a specific locale', function () {
    LegalDocument::create([
        'slug' => 'privacy-policy',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'Politique de Confidentialité',
        'content_markdown' => 'Contenu français',
        'effective_date' => now()->subDay(),
        'is_published' => true,
    ]);

    LegalDocument::create([
        'slug' => 'privacy-policy',
        'version' => '1.0.0',
        'locale' => 'en',
        'title' => 'Privacy Policy',
        'content_markdown' => 'English content',
        'effective_date' => now()->subDay(),
        'is_published' => true,
    ]);

    $response = $this->getJson('/api/legal/documents/privacy-policy?locale=en');

    $response->assertOk()
        ->assertJsonPath('data.locale', 'en')
        ->assertJsonPath('data.title', 'Privacy Policy')
        ->assertJsonPath('data.content_markdown', 'English content');
});

test('falls back to default french locale when requested locale does not exist', function () {
    LegalDocument::create([
        'slug' => 'community-charter',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'Charte de la Communauté',
        'content_markdown' => 'Contenu officiel en français',
        'effective_date' => now()->subDay(),
        'is_published' => true,
    ]);

    $response = $this->getJson('/api/legal/documents/community-charter?locale=es');

    $response->assertOk()
        ->assertJsonPath('data.locale', 'fr')
        ->assertJsonPath('data.title', 'Charte de la Communauté');
});

test('returns 404 for an unknown legal document slug', function () {
    $response = $this->getJson('/api/legal/documents/unknown-document-slug');

    $response->assertStatus(404)
        ->assertJsonPath('error', 'DOCUMENT_NOT_FOUND');
});

test('does not expose unpublished drafts or future effective date documents', function () {
    // Brouillon non publié
    LegalDocument::create([
        'slug' => 'draft-terms',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'Brouillon non publié',
        'content_markdown' => 'Secret draft',
        'effective_date' => now()->subDay(),
        'is_published' => false,
    ]);

    // Document futur non encore en vigueur
    LegalDocument::create([
        'slug' => 'future-terms',
        'version' => '2.0.0',
        'locale' => 'fr',
        'title' => 'Document futur',
        'content_markdown' => 'Future content',
        'effective_date' => now()->addMonth(),
        'is_published' => true,
    ]);

    $this->getJson('/api/legal/documents/draft-terms')
        ->assertStatus(404);

    $this->getJson('/api/legal/documents/future-terms')
        ->assertStatus(404);
});

test('selects the most recent published version when multiple versions exist', function () {
    LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '1.0.0',
        'locale' => 'fr',
        'title' => 'CGU v1',
        'content_markdown' => 'Version 1',
        'effective_date' => now()->subMonths(6),
        'is_published' => true,
    ]);

    LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '2.0.0',
        'locale' => 'fr',
        'title' => 'CGU v2',
        'content_markdown' => 'Version 2',
        'effective_date' => now()->subDays(5),
        'is_published' => true,
    ]);

    $response = $this->getJson('/api/legal/documents/terms-of-service');

    $response->assertOk()
        ->assertJsonPath('data.version', '2.0.0')
        ->assertJsonPath('data.title', 'CGU v2')
        ->assertJsonPath('data.content_markdown', 'Version 2');

    // Consultation spécifique d'une ancienne version par paramètre
    $v1Response = $this->getJson('/api/legal/documents/terms-of-service?version=1.0.0');
    $v1Response->assertOk()
        ->assertJsonPath('data.version', '1.0.0')
        ->assertJsonPath('data.content_markdown', 'Version 1');
});
