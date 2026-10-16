<?php

use App\Enums\Boost\CampaignStatus;
use App\Enums\Reel\ReelStatus;
use App\Models\Boost\Campaign;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->promoter = User::factory()->create([
        'name' => 'Sponsor Promag',
        'city' => 'Douala',
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);

    $this->viewer = User::factory()->create([
        'name' => 'Visiteur Standard',
        'city' => 'Douala',
        'privacy_settings' => [
            'ads_personalization' => true,
        ],
    ]);
});

// =========================================================================
// AXE D : Injection Square Feed
// =========================================================================

test('Feed Square injecte une publication sponsorisée active avec tag is_sponsored et sponsor_branding', function () {
    Passport::actingAs($this->viewer);

    // Créer des posts organiques
    $organicPost1 = Post::factory()->create(['user_id' => $this->promoter->id, 'created_at' => now()->subHours(2)]);
    $organicPost2 = Post::factory()->create(['user_id' => $this->promoter->id, 'created_at' => now()->subHour()]);

    // Créer un post sponsorisé
    $sponsoredPost = Post::factory()->create(['user_id' => $this->promoter->id, 'body' => 'Superbe villa sponsorisée']);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $sponsoredPost->id,
        'status' => CampaignStatus::Active,
        'budget_amount' => '20000.00',
        'spent_amount' => '1000.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    $response = $this->getJson('/api/square/feed');

    $response->assertOk();
    $data = $response->json('data');

    $injected = collect($data)->firstWhere('id', (string) $sponsoredPost->id);
    expect($injected)->not->toBeNull()
        ->and($injected['is_sponsored'])->toBeTrue()
        ->and($injected['campaign_id'])->toBe($campaign->id)
        ->and($injected['sponsored_by'])->toBe($this->promoter->name);
});

test('Feed Square n\'injecte PAS de campagnes en pause, annulées ou suspendues', function () {
    Passport::actingAs($this->viewer);

    $post1 = Post::factory()->create(['user_id' => $this->promoter->id]);
    $post2 = Post::factory()->create(['user_id' => $this->promoter->id]);

    Campaign::factory()->paused()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post1->id,
    ]);

    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post2->id,
        'status' => CampaignStatus::Suspended,
        'stopped_reason' => 'moderation',
    ]);

    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $data = $response->json('data');
    $sponsoredItems = collect($data)->filter(fn ($item) => ($item['is_sponsored'] ?? false) === true);

    expect($sponsoredItems)->toBeEmpty();
});

test('Feed Square n\'injecte PAS de campagne dont le budget est épuisé', function () {
    Passport::actingAs($this->viewer);

    $post = Post::factory()->create(['user_id' => $this->promoter->id]);

    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'budget_amount' => '5000.00',
        'spent_amount' => '5000.00', // Budget épuisé !
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $injected = collect($response->json('data'))->firstWhere('id', (string) $post->id);
    expect($injected['is_sponsored'] ?? false)->toBeFalse();
});

test('Feed Square n\'injecte PAS de campagne dont la date de fin est dépassée (expirée)', function () {
    Passport::actingAs($this->viewer);

    $post = Post::factory()->create(['user_id' => $this->promoter->id]);

    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'budget_amount' => '10000.00',
        'spent_amount' => '1000.00',
        'start_at' => now()->subDays(10),
        'end_at' => now()->subDay(), // Expirée !
    ]);

    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $injected = collect($response->json('data'))->firstWhere('id', (string) $post->id);
    expect($injected['is_sponsored'] ?? false)->toBeFalse();
});

test('Feed Square applique le ciblage géographique strict par ville', function () {
    // Créer un utilisateur à Yaoundé
    $yaoundeUser = User::factory()->create([
        'name' => 'Utilisateur Yaoundé',
        'city' => 'Yaounde',
        'privacy_settings' => ['ads_personalization' => true],
    ]);

    $postDouala = Post::factory()->create(['user_id' => $this->promoter->id]);

    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $postDouala->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'], // Cible uniquement Douala !
    ]);

    // 1. Le visiteur de Douala DOIT voir la campagne
    Passport::actingAs($this->viewer);
    $resDouala = $this->getJson('/api/square/feed');
    $injectedDouala = collect($resDouala->json('data'))->firstWhere('id', (string) $postDouala->id);
    expect($injectedDouala)->not->toBeNull()
        ->and($injectedDouala['is_sponsored'] ?? false)->toBeTrue();

    // 2. Le visiteur de Yaoundé ne doit PAS voir la campagne ciblée sur Douala
    Passport::actingAs($yaoundeUser);
    $resYaounde = $this->getJson('/api/square/feed');
    $injectedYaounde = collect($resYaounde->json('data'))->firstWhere('id', (string) $postDouala->id);
    expect($injectedYaounde['is_sponsored'] ?? false)->toBeFalse();
});

// =========================================================================
// AXE D : Injection Reels Feed
// =========================================================================

test('Feed Reels injecte un Reel sponsorisé actif avec tag is_sponsored et sponsor_branding', function () {
    Passport::actingAs($this->viewer);

    $reel = Reel::factory()->create([
        'user_id' => $this->promoter->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Reel vidéo sponsorisé',
    ]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Reel::class,
        'boostable_id' => (string) $reel->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    $response = $this->getJson('/api/reels/feed');
    $response->assertOk();

    $injected = collect($response->json('data'))->firstWhere('id', (string) $reel->id);
    expect($injected)->not->toBeNull()
        ->and($injected['is_sponsored'])->toBeTrue()
        ->and($injected['campaign_id'])->toBe($campaign->id);
});

test('Feed Reels n\'injecte PAS de Reel dont le traitement a échoué (status failed)', function () {
    Passport::actingAs($this->viewer);

    $failedReel = Reel::factory()->create([
        'user_id' => $this->promoter->id,
        'status' => ReelStatus::Failed,
    ]);

    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Reel::class,
        'boostable_id' => (string) $failedReel->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    $response = $this->getJson('/api/reels/feed');
    $response->assertOk();

    $injected = collect($response->json('data'))->firstWhere('id', (string) $failedReel->id);
    expect($injected)->toBeNull();
});

// =========================================================================
// AXE E : Consentement publicitaire LG (RGPD)
// =========================================================================

test('Consentement LG : un utilisateur ayant désactivé ads_personalization ne reçoit PAS les campagnes avec ciblage démographique personnalisé', function () {
    $refusingUser = User::factory()->create([
        'name' => 'Utilisateur Sans Profilage',
        'city' => 'Douala',
        'privacy_settings' => [
            'ads_personalization' => false, // Refus du profilage !
        ],
    ]);

    $postPersonalized = Post::factory()->create(['user_id' => $this->promoter->id]);

    // Campagne avec ciblage d'intérêts et d'âge (profilage)
    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $postPersonalized->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => [
            'city' => 'Douala',
            'age_min' => 20,
            'age_max' => 40,
            'interests' => ['crypto', 'luxury'],
        ],
    ]);

    Passport::actingAs($refusingUser);
    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $injected = collect($response->json('data'))->firstWhere('id', (string) $postPersonalized->id);
    expect($injected['is_sponsored'] ?? false)->toBeFalse();
});

test('Consentement LG : un utilisateur ayant désactivé ads_personalization reçoit les campagnes génériques sans profilage', function () {
    $refusingUser = User::factory()->create([
        'name' => 'Utilisateur Sans Profilage',
        'city' => 'Douala',
        'privacy_settings' => [
            'ads_personalization' => false,
        ],
    ]);

    $postGeneric = Post::factory()->create(['user_id' => $this->promoter->id]);

    // Campagne sans profilage démographique (seulement géographique ville)
    Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $postGeneric->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => [
            'city' => 'Douala',
        ],
    ]);

    Passport::actingAs($refusingUser);
    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $injected = collect($response->json('data'))->firstWhere('id', (string) $postGeneric->id);
    expect($injected)->not->toBeNull()
        ->and($injected['is_sponsored'])->toBeTrue();
});

// =========================================================================
// AXE D : Mesures et métriques (Impressions & Clics)
// =========================================================================

test('POST /api/boost/campaigns/{id}/impression incrémente de manière atomique l\'impression et la portée', function () {
    Passport::actingAs($this->viewer);

    $post = Post::factory()->create(['user_id' => $this->promoter->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'impressions_count' => 10,
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/impression");

    $response->assertOk()
        ->assertJsonPath('impressions_count', 11);

    expect($campaign->fresh()->impressions_count)->toBe(11)
        ->and($campaign->fresh()->reach_count)->toBe(1);
});

test('POST /api/boost/campaigns/{id}/click incrémente de manière atomique les clics', function () {
    Passport::actingAs($this->viewer);

    $post = Post::factory()->create(['user_id' => $this->promoter->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'clicks_count' => 3,
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/click");

    $response->assertOk()
        ->assertJsonPath('clicks_count', 4);

    expect($campaign->fresh()->clicks_count)->toBe(4);
});

test('POST /api/boost/campaigns/{id}/impression rejette l\'enregistrement sur une campagne non diffusable (422)', function () {
    Passport::actingAs($this->viewer);

    $post = Post::factory()->create(['user_id' => $this->promoter->id]);
    $campaign = Campaign::factory()->paused()->create([
        'user_id' => $this->promoter->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/impression");
    $response->assertStatus(422);
});
