<?php

use App\Enums\Boost\CampaignStatus;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Boost\CampaignService;
use App\Services\Boost\CreditWalletService;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create([
        'name' => 'Fatou Créatrice',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    $this->otherUser = User::factory()->create(['name' => 'Paul Inconnu']);

    $agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $agentPrice = SubscriptionPrice::where('product_id', $agentProduct->id)->first();
    Subscription::create([
        'user_id' => $this->user->id,
        'product_id' => $agentProduct->id,
        'price_id' => $agentPrice->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'amount' => $agentPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => true,
    ]);

    $this->campaignService = app(CampaignService::class);
    $this->walletService = app(CreditWalletService::class);

    // Initialiser le portefeuille de Fatou avec 30 000 XAF
    $this->wallet = CreditWallet::factory()->create([
        'user_id' => $this->user->id,
        'balance' => '30000.00',
        'currency' => 'XAF',
    ]);
});

test('GET /api/boost/campaigns/{id} retourne les détails complets de la campagne pour son propriétaire', function () {
    Passport::actingAs($this->user);

    $post = Post::factory()->create(['user_id' => $this->user->id]);
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'budget_amount' => '12000.00',
        'spent_amount' => '0.00',
        'status' => CampaignStatus::Active,
    ]);

    // Ajouter des métriques quotidiennes
    $this->campaignService->recordDailyMetric($campaign, now()->subDay()->toDateString(), 1500, 60, 1200, 2000.0);
    $this->campaignService->recordDailyMetric($campaign, now()->toDateString(), 1800, 75, 1450, 2000.0);

    $response = $this->getJson("/api/boost/campaigns/{$campaign->id}");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'user_id',
                'kind',
                'boostable_type',
                'boostable_id',
                'content_label',
                'objective' => ['value', 'label'],
                'budget',
                'currency',
                'duration_days',
                'status' => ['value', 'label'],
                'spent',
                'unspent',
                'impressions_count',
                'clicks_count',
                'reach_count',
                'metrics' => [
                    '*' => ['metric_date', 'impressions', 'clicks', 'reach', 'spent'],
                ],
            ],
        ]);

    $data = $response->json('data');
    expect($data['id'])->toBe($campaign->id)
        ->and((float) $data['budget'])->toBe(12000.0)
        ->and((float) $data['unspent'])->toBe(8000.0)
        ->and($data['metrics'])->toHaveCount(2);
});

test('GET /api/boost/campaigns/{id} refuse strictement l\'accès à un utilisateur non propriétaire (IDOR)', function () {
    Passport::actingAs($this->otherUser);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->getJson("/api/boost/campaigns/{$campaign->id}");

    $response->assertStatus(403);
});

test('GET /api/boost/campaigns liste uniquement les campagnes de l\'utilisateur authentifié', function () {
    Passport::actingAs($this->user);

    Campaign::factory()->count(3)->create(['user_id' => $this->user->id]);
    Campaign::factory()->count(2)->create(['user_id' => $this->otherUser->id]);

    $response = $this->getJson('/api/boost/campaigns');

    $response->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);

    expect($response->json('data'))->toHaveCount(3);
    foreach ($response->json('data') as $item) {
        expect($item['user_id'])->toBe($this->user->id);
    }
});

test('POST /api/boost/campaigns/{id}/pause met en pause une campagne active', function () {
    Passport::actingAs($this->user);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'status' => CampaignStatus::Active,
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/pause");

    $response->assertOk()
        ->assertJsonPath('data.status.value', CampaignStatus::Paused->value);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Paused);
});

test('POST /api/boost/campaigns/{id}/pause rejette une campagne qui n\'est pas active', function () {
    Passport::actingAs($this->user);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'status' => CampaignStatus::Pending,
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/pause");

    $response->assertStatus(422)
        ->assertJsonPath('error', 'INVALID_CAMPAIGN_TRANSITION');
});

test('POST /api/boost/campaigns/{id}/resume reprend une campagne en pause', function () {
    Passport::actingAs($this->user);

    $campaign = Campaign::factory()->paused()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/resume");

    $response->assertOk()
        ->assertJsonPath('data.status.value', CampaignStatus::Active->value);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active);
});

test('POST /api/boost/campaigns/{id}/cancel annule la campagne sans restitution de crédits (règle consommation définitive)', function () {
    Passport::actingAs($this->user);

    // Initial: solde portefeuille = 30 000
    // Campagne budget = 15 000, dépensé = 5 000 -> aucun remboursement de crédits
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'budget_amount' => '15000.00',
        'spent_amount' => '5000.00',
        'status' => CampaignStatus::Active,
        'payment_method' => 'credit',
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/cancel", [
        'reason' => 'manual',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status.value', CampaignStatus::Cancelled->value)
        ->assertJsonPath('data.stopped_reason', 'manual');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Cancelled);

    // Règle CB immuable : les crédits dépensés sont définitivement consommés (solde reste à 30 000)
    expect((int) $this->walletService->getBalance($this->user))->toBe(30000);

    // Aucune transaction de remboursement n'est créée
    $this->assertDatabaseMissing('credit_transactions', [
        'wallet_id' => $this->wallet->id,
        'user_id' => $this->user->id,
        'type' => 'campaign_refund',
    ]);
});

test('Un utilisateur tiers ne peut pas modifier, mettre en pause ou annuler la campagne d\'un autre', function () {
    Passport::actingAs($this->otherUser);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'status' => CampaignStatus::Active,
    ]);

    $this->postJson("/api/boost/campaigns/{$campaign->id}/pause")->assertStatus(403);
    $this->postJson("/api/boost/campaigns/{$campaign->id}/cancel")->assertStatus(403);
});

test('POST /api/boost/campaigns/{id}/fund permet de financer une campagne pending avec les crédits BANGO', function () {
    Passport::actingAs($this->user);

    $campaign = Campaign::factory()->pending()->create([
        'user_id' => $this->user->id,
        'budget_amount' => '8000.00',
    ]);

    $response = $this->postJson("/api/boost/campaigns/{$campaign->id}/fund", [
        'idempotency_key' => "fund_test_{$campaign->id}",
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status.value', CampaignStatus::Active->value)
        ->assertJsonPath('data.payment_method', 'credit');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Active)
        ->and($this->walletService->getBalance($this->user))->toBe(22000.0); // 30 000 - 8 000
});

test('recordDailyMetric agrège les impressions, clics, portée et dépense sans écraser les métriques', function () {
    $campaign = Campaign::factory()->create([
        'user_id' => $this->user->id,
        'impressions_count' => 0,
        'clicks_count' => 0,
        'reach_count' => 0,
        'spent_amount' => '0.00',
    ]);

    $today = now()->toDateString();

    // Premier lot de métriques
    $this->campaignService->recordDailyMetric($campaign, $today, 500, 25, 400, 1000.0);

    // Deuxième lot de métriques pour la même journée
    $metric = $this->campaignService->recordDailyMetric($campaign, $today, 700, 35, 600, 1500.0);

    // L'agrégation quotidienne doit cumuler
    expect($metric->impressions)->toBe(1200)
        ->and($metric->clicks)->toBe(60)
        ->and($metric->reach)->toBe(1000)
        ->and((float) $metric->spent)->toBe(2500.0);

    // Les compteurs globaux de la campagne doivent être incrémentés
    $freshCampaign = $campaign->fresh();
    expect($freshCampaign->impressions_count)->toBe(1200)
        ->and($freshCampaign->clicks_count)->toBe(60)
        ->and($freshCampaign->reach_count)->toBe(1000)
        ->and((float) $freshCampaign->spent_amount)->toBe(2500.0);
});
