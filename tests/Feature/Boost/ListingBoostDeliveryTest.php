<?php

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Enums\Reel\ReelStatus;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
use App\Models\Listing\Listing;
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

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->first();

    $this->agent = User::factory()->create([
        'name' => 'Agent Immobilier Pro',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
        'city' => 'Douala',
    ]);

    Subscription::create([
        'user_id' => $this->agent->id,
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
        'user_id' => $this->agent->id,
        'balance' => '50000.00',
        'currency' => 'XAF',
    ]);

    $this->viewer = User::factory()->create([
        'name' => 'Visiteur Client',
        'city' => 'Douala',
        'privacy_settings' => [
            'ads_personalization' => true,
        ],
    ]);
});

test('un bien booste est injecte en tete des resultats de recherche et du fil Immo', function () {
    Passport::actingAs($this->viewer);

    // 1. Créer des biens organiques ordinaires
    $listing1 = Listing::factory()->create([
        'owner_id' => $this->agent->id,
        'city' => 'Douala',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Published,
        'price' => 150000,
        'published_at' => now()->subHours(5),
    ]);

    $listing2 = Listing::factory()->create([
        'owner_id' => $this->agent->id,
        'city' => 'Douala',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Published,
        'price' => 180000,
        'published_at' => now()->subHour(),
    ]);

    // 2. Créer un bien boosté (publié plus anciennement)
    $boostedListing = Listing::factory()->create([
        'owner_id' => $this->agent->id,
        'city' => 'Douala',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Published,
        'price' => 200000,
        'published_at' => now()->subDays(3),
    ]);

    // Créer la campagne active sur ce bien
    $campaign = Campaign::factory()->create([
        'user_id' => $this->agent->id,
        'boostable_type' => Listing::class,
        'boostable_id' => $boostedListing->id,
        'status' => CampaignStatus::Active,
        'objective' => CampaignObjective::Messages,
        'budget_amount' => '30000.00',
        'spent_amount' => '1000.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
        'metadata' => ['sponsored_by' => 'Cabinet Immobilier Douala'],
    ]);

    // Appel du fil / recherche Immo
    $response = $this->getJson('/api/listings?city=Douala&mode=rent');

    $response->assertOk();
    $data = $response->json('data');

    // Le bien boosté doit être en première position (index 0)
    expect($data)->not->toBeEmpty()
        ->and($data[0]['id'])->toBe($boostedListing->id)
        ->and($data[0]['is_sponsored'])->toBeTrue()
        ->and($data[0]['campaign_id'])->toBe($campaign->id)
        ->and($data[0]['sponsored_by'])->toBe('Cabinet Immobilier Douala');
});

test('un bien booste ne correspondant pas aux filtres de recherche nest pas injecte', function () {
    Passport::actingAs($this->viewer);

    // Bien boosté à Douala en location
    $boostedListing = Listing::factory()->create([
        'owner_id' => $this->agent->id,
        'city' => 'Douala',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Published,
        'price' => 200000,
        'published_at' => now()->subDays(3),
    ]);

    Campaign::factory()->create([
        'user_id' => $this->agent->id,
        'boostable_type' => Listing::class,
        'boostable_id' => $boostedListing->id,
        'status' => CampaignStatus::Active,
        'objective' => CampaignObjective::Messages,
        'budget_amount' => '30000.00',
        'spent_amount' => '1000.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(5),
        'targeting' => ['city' => 'Douala'],
    ]);

    // Recherche sur Yaounde
    $response = $this->getJson('/api/listings?city=Yaounde');

    $response->assertOk();
    $data = $response->json('data');

    $ids = collect($data)->pluck('id')->all();
    expect($ids)->not->toContain($boostedListing->id);
});

test('un reel lie a un bien booste est diffuse dans le feed Reels', function () {
    Passport::actingAs($this->viewer);

    $boostedListing = Listing::factory()->create([
        'owner_id' => $this->agent->id,
        'city' => 'Douala',
        'status' => ListingStatus::Published,
    ]);

    // Reel lié au bien
    $reel = Reel::factory()->create([
        'user_id' => $this->agent->id,
        'listing_id' => $boostedListing->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Visite exclusive du bien',
    ]);

    // Campagne sur le Listing
    $campaign = Campaign::factory()->create([
        'user_id' => $this->agent->id,
        'boostable_type' => Listing::class,
        'boostable_id' => $boostedListing->id,
        'status' => CampaignStatus::Active,
        'objective' => CampaignObjective::Messages,
        'budget_amount' => '25000.00',
        'spent_amount' => '500.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(3),
        'targeting' => ['city' => 'Douala'],
    ]);

    // Reels organiques
    Reel::factory()->count(3)->create([
        'status' => ReelStatus::Ready,
    ]);

    $response = $this->getJson('/api/reels/feed');
    $response->assertOk();

    $reels = $response->json('data');
    $sponsoredReels = collect($reels)->filter(fn ($r) => ($r['is_sponsored'] ?? false) === true);

    expect($sponsoredReels)->not->toBeEmpty();
    $firstSponsored = $sponsoredReels->first();
    expect($firstSponsored['id'])->toBe($reel->id)
        ->and($firstSponsored['campaign_id'])->toBe($campaign->id);
});

test('une publication liee au bien ou a lauteur est diffusee dans le Square', function () {
    Passport::actingAs($this->viewer);

    $boostedListing = Listing::factory()->create([
        'owner_id' => $this->agent->id,
        'city' => 'Douala',
        'status' => ListingStatus::Published,
    ]);

    // Publication Square de l'agent
    $post = Post::factory()->create([
        'user_id' => $this->agent->id,
        'body' => 'Découvrez notre nouvelle annonce exclusive !',
    ]);

    $campaign = Campaign::factory()->create([
        'user_id' => $this->agent->id,
        'boostable_type' => Listing::class,
        'boostable_id' => $boostedListing->id,
        'status' => CampaignStatus::Active,
        'objective' => CampaignObjective::Messages,
        'budget_amount' => '25000.00',
        'spent_amount' => '500.00',
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(3),
        'targeting' => ['city' => 'Douala'],
    ]);

    // Posts organiques
    Post::factory()->count(4)->create();

    $response = $this->getJson('/api/square/feed');
    $response->assertOk();

    $posts = $response->json('data');
    $sponsoredPosts = collect($posts)->filter(fn ($p) => ($p['is_sponsored'] ?? false) === true);

    expect($sponsoredPosts)->not->toBeEmpty();
    $firstSponsored = $sponsoredPosts->first();
    expect((string) $firstSponsored['id'])->toBe((string) $post->id)
        ->and($firstSponsored['campaign_id'])->toBe($campaign->id);
});
