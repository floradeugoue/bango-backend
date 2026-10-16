<?php

use App\Enums\Boost\CampaignStatus;
use App\Models\Boost\CreditWallet;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create([
        'name' => 'Jean Promoteur',
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);
    $this->otherUser = User::factory()->create(['name' => 'Autre Créateur']);

    $landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $landlordPrice = SubscriptionPrice::where('product_id', $landlordProduct->id)->first();
    Subscription::create([
        'user_id' => $this->user->id,
        'product_id' => $landlordProduct->id,
        'price_id' => $landlordPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $landlordPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => true,
    ]);

    // Créer un portefeuille approvisionné pour l'utilisateur
    $this->wallet = CreditWallet::factory()->create([
        'user_id' => $this->user->id,
        'balance' => '50000.00',
        'currency' => 'XAF',
    ]);
});

test('POST /api/boost/estimate calcule la portée estimative avec la baseline CPM du catalogue', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/boost/estimate', [
        'budget' => 2180,
        'currency' => 'XAF',
        'duration_days' => 7,
        'targeting' => [
            'city' => 'Douala',
            'neighbourhoods' => ['Akwa', 'Bonanjo'],
            'radius_km' => 10,
            'age_min' => 20,
            'age_max' => 45,
            'interests' => ['immo', 'student'],
        ],
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'budget',
                'currency',
                'duration_days',
                'daily_budget',
                'reach' => ['low', 'high', 'daily_low', 'daily_high'],
                'cpm',
                'estimated_audience_pool',
                'is_guaranteed',
                'disclaimer',
                'assumptions',
            ],
        ]);

    $data = $response->json('data');
    expect($data['reach']['low'])->toBe(1000)
        ->and($data['reach']['high'])->toBe(1290)
        ->and($data['is_guaranteed'])->toBeFalse()
        ->and($data['disclaimer'])->toContain('indicative');
});

test('POST /api/boost/estimate valide le contenu spécifié et autorise le propriétaire', function () {
    Passport::actingAs($this->user);

    $post = Post::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/boost/estimate', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 5,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.budget', 5000);
});

test('POST /api/boost/estimate refuse la validation si le contenu appartient à un tiers', function () {
    Passport::actingAs($this->user);

    $foreignPost = Post::factory()->create(['user_id' => $this->otherUser->id]);

    $response = $this->postJson('/api/boost/estimate', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $foreignPost->id,
        'budget' => 5000,
    ]);

    $response->assertStatus(403);
});

test('POST /api/boost/estimate rejette un budget inférieur au seuil minimal', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/boost/estimate', [
        'budget' => 50, // Min is 100
        'duration_days' => 5,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['budget']);
});

test('POST /api/boost/campaigns crée une campagne pour un Post Square avec débit immédiat en Crédit BANGO', function () {
    Passport::actingAs($this->user);

    $post = Post::factory()->create([
        'user_id' => $this->user->id,
        'body' => 'Super villa à louer à Bonapriso',
    ]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'objective' => 'reach',
        'budget' => 10000,
        'currency' => 'XAF',
        'duration_days' => 5,
        'payment_method' => 'credit',
        'targeting' => [
            'city' => 'Douala',
            'neighbourhoods' => ['Bonapriso'],
            'radius_km' => 5,
            'age_min' => 25,
            'age_max' => 50,
            'interests' => ['immo'],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.kind', 'post')
        ->assertJsonPath('data.status.value', CampaignStatus::Active->value)
        ->assertJsonPath('data.budget', 10000)
        ->assertJsonPath('data.duration_days', 5);

    $campaignId = $response->json('data.id');

    $this->assertDatabaseHas('campaigns', [
        'id' => $campaignId,
        'user_id' => $this->user->id,
        'boostable_id' => (string) $post->id,
        'status' => CampaignStatus::Active->value,
        'payment_method' => 'credit',
    ]);

    // Vérifier que le portefeuille a été débité de 10 000 XAF
    expect((float) $this->wallet->fresh()->balance)->toBe(40000.00);

    // Vérifier l'écriture dans le grand livre
    $this->assertDatabaseHas('credit_transactions', [
        'wallet_id' => $this->wallet->id,
        'user_id' => $this->user->id,
        'reference_id' => $campaignId,
        'amount' => 10000,
    ]);
});

test('POST /api/boost/campaigns crée une campagne pour un Reel vidéo avec paiement externe en attente', function () {
    Passport::actingAs($this->user);

    $reel = Reel::factory()->create([
        'user_id' => $this->user->id,
        'caption' => 'Découvrez le nouvel appartement meublé',
    ]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'reel',
        'boostable_id' => (string) $reel->id,
        'objective' => 'profile_visits',
        'budget' => 15000,
        'currency' => 'XAF',
        'duration_days' => 10,
        'payment_method' => 'external',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.kind', 'reel')
        ->assertJsonPath('data.status.value', CampaignStatus::Pending->value)
        ->assertJsonPath('data.budget', 15000);

    // Le portefeuille ne doit PAS avoir été débité
    expect((float) $this->wallet->fresh()->balance)->toBe(50000.00);
});

test('POST /api/boost/campaigns refuse de promouvoir le contenu d\'un tiers', function () {
    Passport::actingAs($this->user);

    $foreignPost = Post::factory()->create(['user_id' => $this->otherUser->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $foreignPost->id,
        'budget' => 5000,
        'duration_days' => 3,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403);
});

test('POST /api/boost/campaigns refuse les modèles non autorisés par la liste blanche', function () {
    Passport::actingAs($this->user);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'user', // Non autorisé
        'boostable_id' => (string) $this->user->id,
        'budget' => 5000,
        'duration_days' => 3,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['boostable_type']);
});

test('POST /api/boost/campaigns refuse de promouvoir une publication archivée', function () {
    Passport::actingAs($this->user);

    $archivedPost = Post::factory()->archived()->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $archivedPost->id,
        'budget' => 5000,
        'duration_days' => 3,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(422);
});

test('POST /api/boost/campaigns refuse le paiement par crédits si le solde est insuffisant', function () {
    Passport::actingAs($this->user);

    // Réduire le solde à 1 000 XAF
    $this->wallet->update(['balance' => '1000.00']);

    $post = Post::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000, // Supérieur au solde (1 000)
        'duration_days' => 3,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(422);

    // Le solde doit rester intact
    expect((float) $this->wallet->fresh()->balance)->toBe(1000.00);
});
