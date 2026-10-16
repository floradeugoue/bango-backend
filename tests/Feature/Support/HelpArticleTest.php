<?php

use App\Enums\Support\HelpArticleCategory;
use App\Models\Support\HelpArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('GET /api/help/articles liste uniquement les articles publiés avec pagination', function () {
    HelpArticle::create([
        'slug' => 'comment-creer-un-compte',
        'category' => HelpArticleCategory::AccountAuth,
        'title' => 'Comment créer un compte BANGO ?',
        'content_markdown' => 'Pour créer un compte, téléchargez l\'application...',
        'is_published' => true,
        'published_at' => now(),
        'order' => 1,
    ]);

    HelpArticle::create([
        'slug' => 'securiser-mon-compte',
        'category' => HelpArticleCategory::AccountAuth,
        'title' => 'Comment sécuriser mon compte ?',
        'content_markdown' => 'Activez la 2FA depuis les paramètres...',
        'is_published' => true,
        'published_at' => now(),
        'order' => 2,
    ]);

    // Article non publié (brouillon)
    HelpArticle::create([
        'slug' => 'article-brouillon-interne',
        'category' => HelpArticleCategory::Other,
        'title' => 'Article brouillon',
        'content_markdown' => 'Ce contenu ne doit pas être visible.',
        'is_published' => false,
    ]);

    $response = $this->getJson('/api/help/articles');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.slug', 'comment-creer-un-compte')
        ->assertJsonPath('data.1.slug', 'securiser-mon-compte')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'slug',
                    'category' => ['value', 'label'],
                    'title',
                    'content_markdown',
                    'helpful_count',
                    'unhelpful_count',
                    'views_count',
                    'locale',
                    'created_at',
                ],
            ],
            'meta' => ['current_page', 'total'],
        ]);
});

test('GET /api/help/articles filtre par catégorie et recherche textuelle', function () {
    HelpArticle::create([
        'slug' => 'paiement-wave-et-orange',
        'category' => HelpArticleCategory::PaymentWallet,
        'title' => 'Moyens de paiement acceptés',
        'content_markdown' => 'BANGO accepte Wave, Orange Money et MTN.',
        'is_published' => true,
    ]);

    HelpArticle::create([
        'slug' => 'reserver-une-visite',
        'category' => HelpArticleCategory::RealEstateVisits,
        'title' => 'Réserver une visite immobilière',
        'content_markdown' => 'Sélectionnez un créneau de visite sur la fiche du bien.',
        'is_published' => true,
    ]);

    // Filtre par catégorie
    $catResponse = $this->getJson('/api/help/articles?category='.HelpArticleCategory::PaymentWallet->value);
    $catResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'paiement-wave-et-orange');

    // Recherche par mot-clé dans le contenu
    $searchResponse = $this->getJson('/api/help/articles?q=créneau');
    $searchResponse->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'reserver-une-visite');

    // Recherche sans résultat
    $emptyResponse = $this->getJson('/api/help/articles?q=mot_inexistant_xyz');
    $emptyResponse->assertOk()
        ->assertJsonCount(0, 'data');
});

test('GET /api/help/articles/{slug} affiche l\'article et incrémente views_count', function () {
    $article = HelpArticle::create([
        'slug' => 'visites-virtuelles-360',
        'category' => HelpArticleCategory::RealEstateVisits,
        'title' => 'Comment fonctionnent les visites 360 ?',
        'content_markdown' => 'Les visites virtuelles permettent d\'explorer le logement.',
        'is_published' => true,
        'views_count' => 5,
    ]);

    $response = $this->getJson('/api/help/articles/visites-virtuelles-360');

    $response->assertOk()
        ->assertJsonPath('data.slug', 'visites-virtuelles-360')
        ->assertJsonPath('data.title', 'Comment fonctionnent les visites 360 ?')
        ->assertJsonPath('data.views_count', 6);

    expect($article->fresh()->views_count)->toBe(6);
});

test('GET /api/help/articles/{slug} renvoie 404 pour un article inexistant ou non publié', function () {
    HelpArticle::create([
        'slug' => 'article-brouillon-prive',
        'category' => HelpArticleCategory::Other,
        'title' => 'Brouillon privé',
        'content_markdown' => 'Contenu confidentiel.',
        'is_published' => false,
    ]);

    $this->getJson('/api/help/articles/inexistant')->assertNotFound();
    $this->getJson('/api/help/articles/article-brouillon-prive')->assertNotFound();
});

test('POST /api/help/articles/{id}/feedback permet de voter utile ou inutile avec déduplication et bascule', function () {
    $article = HelpArticle::create([
        'slug' => 'gestion-du-portefeuille',
        'category' => HelpArticleCategory::PaymentWallet,
        'title' => 'Gérer son portefeuille BANGO',
        'content_markdown' => 'Rechargez votre solde par Mobile Money.',
        'is_published' => true,
        'helpful_count' => 0,
        'unhelpful_count' => 0,
    ]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    // 1. Premier vote positif
    $vote1 = $this->postJson("/api/help/articles/{$article->id}/feedback", [
        'helpful' => true,
    ]);

    $vote1->assertOk()
        ->assertJsonPath('data.helpful', true)
        ->assertJsonPath('data.helpful_count', 1)
        ->assertJsonPath('data.unhelpful_count', 0)
        ->assertJsonPath('data.changed', true);

    expect($article->fresh()->helpful_count)->toBe(1)
        ->and($article->fresh()->unhelpful_count)->toBe(0);

    // 2. Répétition identique (idempotence : pas de double incrément)
    $vote2 = $this->postJson("/api/help/articles/{$article->id}/feedback", [
        'helpful' => true,
    ]);

    $vote2->assertOk()
        ->assertJsonPath('data.helpful_count', 1)
        ->assertJsonPath('data.changed', false);

    expect($article->fresh()->helpful_count)->toBe(1);

    // 3. Bascule du vote vers "inutile" (helpful = false)
    $vote3 = $this->postJson("/api/help/articles/{$article->id}/feedback", [
        'helpful' => false,
    ]);

    $vote3->assertOk()
        ->assertJsonPath('data.helpful', false)
        ->assertJsonPath('data.helpful_count', 0)
        ->assertJsonPath('data.unhelpful_count', 1)
        ->assertJsonPath('data.changed', true);

    expect($article->fresh()->helpful_count)->toBe(0)
        ->and($article->fresh()->unhelpful_count)->toBe(1);
});

test('POST /api/help/articles/{id}/feedback valide les entrées et rejette un article inconnu', function () {
    $article = HelpArticle::create([
        'slug' => 'test-validation',
        'category' => HelpArticleCategory::Other,
        'title' => 'Test validation feedback',
        'content_markdown' => 'Markdown test',
        'is_published' => true,
    ]);

    // Validation erreur (champ helpful manquant)
    $this->postJson("/api/help/articles/{$article->id}/feedback", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['helpful']);

    // Article inexistant
    $this->postJson('/api/help/articles/99999/feedback', ['helpful' => true])
        ->assertNotFound();
});
