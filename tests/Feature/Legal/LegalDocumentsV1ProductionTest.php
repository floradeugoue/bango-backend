<?php

use App\Models\Legal\LegalDocument;
use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use Database\Seeders\LegalDocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('serves legal documents in version 1.0 with effective date 2026-11-22 for all required slugs and locales', function () {
    $this->seed(LegalDocumentSeeder::class);

    // 1. terms-of-service (fr & en)
    $resTermsFr = $this->getJson('/api/legal/documents/terms-of-service?locale=fr');
    $resTermsFr->assertOk();
    expect($resTermsFr->json('data.version'))->toBe('1.0')
        ->and($resTermsFr->json('data.locale'))->toBe('fr')
        ->and($resTermsFr->json('data.effective_date'))->toContain('2026-11-22')
        ->and($resTermsFr->json('data.content_markdown'))->toContain('BANGO')
        ->and($resTermsFr->json('data.content_markdown'))->not->toContain('CCEDA Finance')
        ->and($resTermsFr->json('data.content_markdown'))->not->toContain('Sénégalais');

    $resTermsEn = $this->getJson('/api/legal/documents/terms-of-service?locale=en');
    $resTermsEn->assertOk();
    expect($resTermsEn->json('data.version'))->toBe('1.0')
        ->and($resTermsEn->json('data.locale'))->toBe('en')
        ->and($resTermsEn->json('data.effective_date'))->toContain('2026-11-22')
        ->and($resTermsEn->json('data.content_markdown'))->toContain('Terms of Use')
        ->and($resTermsEn->json('data.content_markdown'))->toContain('BANGO')
        ->and($resTermsEn->json('data.content_markdown'))->not->toContain('CCEDA Finance');

    // 2. privacy-policy (fr & en)
    $resPrivacyFr = $this->getJson('/api/legal/documents/privacy-policy?locale=fr');
    $resPrivacyFr->assertOk();
    expect($resPrivacyFr->json('data.version'))->toBe('1.0')
        ->and($resPrivacyFr->json('data.locale'))->toBe('fr')
        ->and($resPrivacyFr->json('data.effective_date'))->toContain('2026-11-22')
        ->and($resPrivacyFr->json('data.content_markdown'))->toContain('Données biométriques')
        ->and($resPrivacyFr->json('data.content_markdown'))->toContain('Contabo, Allemagne')
        ->and($resPrivacyFr->json('data.content_markdown'))->not->toContain('CCEDA Finance')
        ->and($resPrivacyFr->json('data.content_markdown'))->not->toContain('carte professionnelle');

    $resPrivacyEn = $this->getJson('/api/legal/documents/privacy-policy?locale=en');
    $resPrivacyEn->assertOk();
    expect($resPrivacyEn->json('data.version'))->toBe('1.0')
        ->and($resPrivacyEn->json('data.locale'))->toBe('en')
        ->and($resPrivacyEn->json('data.effective_date'))->toContain('2026-11-22')
        ->and($resPrivacyEn->json('data.content_markdown'))->toContain('Biometric data')
        ->and($resPrivacyEn->json('data.content_markdown'))->toContain('Contabo, Germany')
        ->and($resPrivacyEn->json('data.content_markdown'))->not->toContain('CCEDA Finance');

    // 3. content-rules (fr & en)
    $resRulesFr = $this->getJson('/api/legal/documents/content-rules?locale=fr');
    $resRulesFr->assertOk();
    expect($resRulesFr->json('data.version'))->toBe('1.0')
        ->and($resRulesFr->json('data.locale'))->toBe('fr')
        ->and($resRulesFr->json('data.effective_date'))->toContain('2026-11-22')
        ->and($resRulesFr->json('data.content_markdown'))->toContain('Règles de contenu');

    $resRulesEn = $this->getJson('/api/legal/documents/content-rules?locale=en');
    $resRulesEn->assertOk();
    expect($resRulesEn->json('data.version'))->toBe('1.0')
        ->and($resRulesEn->json('data.locale'))->toBe('en')
        ->and($resRulesEn->json('data.effective_date'))->toContain('2026-11-22')
        ->and($resRulesEn->json('data.content_markdown'))->toContain('Content rules');
});

test('test acceptances are reset to zero so every account must accept version 1.0', function () {
    $user = User::factory()->create([
        'accepted_terms_version' => '3.0',
        'terms_accepted_at' => now(),
    ]);

    TermsAcceptance::create([
        'user_id' => $user->id,
        'version' => '3.0',
        'document_slug' => 'terms-of-service',
        'locale' => 'fr',
        'accepted_at' => now(),
    ]);

    // Exécuter le seeder
    $this->seed(LegalDocumentSeeder::class);

    // Vérifier la remise à zéro
    expect(TermsAcceptance::count())->toBe(0);

    $user->refresh();
    expect($user->accepted_terms_version)->toBeNull()
        ->and($user->terms_accepted_at)->toBeNull();

    // Vérifier qu'aucune version 3.0 n'existe dans les documents légaux
    expect(LegalDocument::where('version', '3.0')->count())->toBe(0);
});
