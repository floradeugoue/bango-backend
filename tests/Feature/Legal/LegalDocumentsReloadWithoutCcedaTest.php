<?php

use App\Models\Legal\LegalDocument;
use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('migration updates legal documents v1.0 without cceda and preserves user acceptances', function () {
    // 1. Simuler un état existant en production où les documents ont encore 'CCEDA Finance'
    $docFr = LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '1.0',
        'locale' => 'fr',
        'title' => 'Conditions générales d’utilisation',
        'content_markdown' => 'Ancien texte mentionnant CCEDA Finance.',
        'effective_date' => '2026-11-22 00:00:00',
        'is_published' => true,
        'published_at' => '2026-11-22 00:00:00',
        'is_mandatory' => true,
    ]);

    $docEn = LegalDocument::create([
        'slug' => 'terms-of-service',
        'version' => '1.0',
        'locale' => 'en',
        'title' => 'Terms of Use',
        'content_markdown' => 'Old text mentioning CCEDA Finance.',
        'effective_date' => '2026-11-22 00:00:00',
        'is_published' => true,
        'published_at' => '2026-11-22 00:00:00',
        'is_mandatory' => true,
    ]);

    // 2. Un utilisateur a déjà accepté la version 1.0
    $user = User::factory()->create([
        'accepted_terms_version' => '1.0',
        'terms_accepted_at' => now(),
    ]);

    $acceptance = TermsAcceptance::create([
        'user_id' => $user->id,
        'version' => '1.0',
        'document_slug' => 'terms-of-service',
        'locale' => 'fr',
        'accepted_at' => now(),
    ]);

    // 3. Exécuter la migration
    config(['legal.force_migration_in_testing' => true]);
    $migration = require database_path('migrations/2026_11_25_175000_update_legal_documents_v1_content_without_cceda.php');
    $migration->up();

    // 4. Vérifier que les documents sont rechargés sans CCEDA et contiennent BANGO
    $docFr->refresh();
    expect($docFr->content_markdown)->not->toContain('CCEDA Finance')
        ->and($docFr->content_markdown)->toContain('BANGO')
        ->and($docFr->version)->toBe('1.0');

    $docEn->refresh();
    expect($docEn->content_markdown)->not->toContain('CCEDA Finance')
        ->and($docEn->content_markdown)->toContain('BANGO')
        ->and($docEn->version)->toBe('1.0');

    // 5. Vérifier que les acceptations existantes sont préservées (aucun reset)
    expect(TermsAcceptance::count())->toBe(1);
    $user->refresh();
    expect($user->accepted_terms_version)->toBe('1.0')
        ->and($user->terms_accepted_at)->not->toBeNull();
});
