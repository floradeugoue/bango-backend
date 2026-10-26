<?php

use App\Enums\Boost\CampaignStatus;
use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Listing\ListingStatus;
use App\Http\Resources\Listing\ListingResource;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
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

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->first();

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->first();

    // Helper pour créer un agent avec badge actif
    $this->createAgent = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'agent',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $this->agentProduct->id,
            'price_id' => $this->agentPrice->id,
            'badge_type' => 'agent',
            'cadence' => 'monthly',
            'amount' => $this->agentPrice->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        CreditWallet::factory()->create([
            'user_id' => $user->id,
            'balance' => '50000.00',
            'currency' => 'XAF',
        ]);

        return $user;
    };

    // Helper pour créer un bailleur avec badge actif
    $this->createLandlord = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'landlord',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $this->landlordProduct->id,
            'price_id' => $this->landlordPrice->id,
            'badge_type' => 'landlord',
            'cadence' => 'monthly',
            'amount' => $this->landlordPrice->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        CreditWallet::factory()->create([
            'user_id' => $user->id,
            'balance' => '50000.00',
            'currency' => 'XAF',
        ]);

        return $user;
    };
});

test('un bailleur sans badge Agent ne peut pas booster son propre bien (403 listing_boost_agents_only)', function () {
    $landlord = ($this->createLandlord)();
    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($landlord);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'listing',
        'boostable_id' => (string) $listing->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('kind', 'listing_boost_agents_only');
});

test('un utilisateur standard sans badge ne peut pas booster un bien (403 listing_boost_agents_only)', function () {
    $user = User::factory()->create([
        'badge' => 'none',
        'is_verified' => false,
    ]);
    CreditWallet::factory()->create(['user_id' => $user->id, 'balance' => 50000]);

    $listing = Listing::factory()->create([
        'owner_id' => $user->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($user);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'listing',
        'boostable_id' => (string) $listing->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('kind', 'listing_boost_agents_only');
});

test('un agent avec badge Agent actif peut booster son propre bien', function () {
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->create([
        'owner_id' => $agent->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'listing',
        'boostable_id' => (string) $listing->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.kind', 'listing');
});

test('un agent avec badge Agent actif peut booster un bien qui lui est confié en délégation active', function () {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => ListingStatus::Published,
    ]);

    Delegation::create([
        'listing_id' => $listing->id,
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subDay(),
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'listing',
        'boostable_id' => (string) $listing->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.kind', 'listing');
});

test('un agent ne peut pas booster un bien d un tiers sans délégation active sur ce bien (403 listing_boost_agents_only)', function () {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'listing',
        'boostable_id' => (string) $listing->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('kind', 'listing_boost_agents_only');
});

test('un agent avec une délégation révoquée ne peut pas booster le bien (403 listing_boost_agents_only)', function () {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => ListingStatus::Published,
    ]);

    Delegation::create([
        'listing_id' => $listing->id,
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'status' => DelegationStatus::Revoked,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDays(2),
        'answered_at' => now()->subDays(2),
        'revoked_at' => now()->subDay(),
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'listing',
        'boostable_id' => (string) $listing->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('kind', 'listing_boost_agents_only');
});

test('publications restent boostables par leur auteur', function () {
    $author = User::factory()->create([
        'badge' => 'none',
        'status' => 'active',
    ]);
    CreditWallet::factory()->create(['user_id' => $author->id, 'balance' => 50000]);

    $post = Post::factory()->create(['user_id' => $author->id]);

    Passport::actingAs($author);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'budget' => 5000,
        'duration_days' => 7,
        'payment_method' => 'credit',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status.value', 'active')
        ->assertJsonPath('data.kind', 'post');
});

test('ListingResource rend is_sponsored et sponsored_by quand une campagne active porte sur le bien', function () {
    $agent = ($this->createAgent)(['name' => 'Cabinet Immobilier Jean']);

    $listing = Listing::factory()->create([
        'owner_id' => $agent->id,
        'status' => ListingStatus::Published,
    ]);

    // Sans campagne active
    $resourceBefore = (new ListingResource($listing))->toArray(request());
    expect($resourceBefore['is_sponsored'])->toBeFalse()
        ->and($resourceBefore['sponsored_by'])->toBeNull();

    // Avec campagne active sur le bien
    Campaign::factory()->create([
        'user_id' => $agent->id,
        'boostable_type' => Listing::class,
        'boostable_id' => $listing->id,
        'status' => CampaignStatus::Active,
        'budget_amount' => 5000,
        'spent_amount' => 1000,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
    ]);

    $listing->refresh();
    $resourceAfter = (new ListingResource($listing))->toArray(request());

    expect($resourceAfter['is_sponsored'])->toBeTrue()
        ->and($resourceAfter['sponsored_by'])->toBe('Cabinet Immobilier Jean');
});
