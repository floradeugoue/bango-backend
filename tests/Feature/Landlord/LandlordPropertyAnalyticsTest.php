<?php

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingDailyMetric;
use App\Models\Listing\ListingSave;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('rejects unauthenticated requests on landlord property analytics endpoint', function () {
    getJson('/api/landlord/properties/00000000-0000-0000-0000-000000000000/analytics')->assertStatus(401);
});

it('returns 404 for a non-existent property', function () {
    $landlord = User::factory()->create();
    Passport::actingAs($landlord);

    getJson('/api/landlord/properties/00000000-0000-0000-0000-000000000000/analytics')->assertStatus(404);
});

it('allows the property owner to view 30 days analytics with zero-filled series for inactive days', function () {
    $landlord = User::factory()->create(['name' => 'Bailleur Propriétaire']);
    Passport::actingAs($landlord);

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'title' => 'Villa Bonapriso',
        'mode' => ListingMode::Rent,
        'kind' => ListingKind::Villa,
        'status' => ListingStatus::Published,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'price' => 500000,
        'currency' => 'XAF',
    ]);

    $response = getJson("/api/landlord/properties/{$listing->id}/analytics");

    $response->assertStatus(200)
        ->assertJsonPath('data.listing_id', (string) $listing->id)
        ->assertJsonPath('data.period_days', 30)
        ->assertJsonCount(30, 'data.series')
        ->assertJsonPath('data.totals.views', 0)
        ->assertJsonPath('data.totals.video_views', 0)
        ->assertJsonPath('data.totals.favorites', 0)
        ->assertJsonPath('data.totals.contacts', 0);

    // Verify first and last date format in series
    $series = $response->json('data.series');
    expect($series[0])->toHaveKeys(['date', 'views', 'video_views', 'favorites', 'contacts']);
    expect($series[0]['views'])->toBe(0);
});

it('forbids another landlord from accessing property analytics', function () {
    $owner = User::factory()->create(['name' => 'Bailleur A']);
    $otherLandlord = User::factory()->create(['name' => 'Bailleur B']);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($otherLandlord);

    getJson("/api/landlord/properties/{$listing->id}/analytics")->assertStatus(403);
});

it('allows an assigned agent to access property analytics', function () {
    $owner = User::factory()->create(['name' => 'Bailleur']);
    $agent = User::factory()->create(['name' => 'Agent Immobilier']);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'agent_id' => $agent->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($agent);

    getJson("/api/landlord/properties/{$listing->id}/analytics")->assertStatus(200);
});

it('accurately aggregates daily metrics, favorites, and contacts over the 30-day period', function () {
    $landlord = User::factory()->create(['name' => 'Bailleur Test Metrics']);
    $seeker = User::factory()->create(['name' => 'Chercheur']);
    Passport::actingAs($landlord);

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'status' => ListingStatus::Published,
    ]);

    $today = now()->startOfDay();
    $yesterday = now()->subDay()->startOfDay();
    $tooOld = now()->subDays(40)->startOfDay();

    // 1. Daily metrics (views & video views)
    ListingDailyMetric::create([
        'listing_id' => $listing->id,
        'metric_date' => $today->toDateString(),
        'views' => 25,
        'video_views' => 10,
        'favorites' => 0,
        'contacts' => 0,
    ]);

    ListingDailyMetric::create([
        'listing_id' => $listing->id,
        'metric_date' => $yesterday->toDateString(),
        'views' => 15,
        'video_views' => 5,
        'favorites' => 0,
        'contacts' => 0,
    ]);

    // Metric outside 30-day window
    ListingDailyMetric::create([
        'listing_id' => $listing->id,
        'metric_date' => $tooOld->toDateString(),
        'views' => 100,
        'video_views' => 50,
        'favorites' => 0,
        'contacts' => 0,
    ]);

    // 2. Real favorite save
    ListingSave::create([
        'listing_id' => $listing->id,
        'user_id' => $seeker->id,
        'created_at' => $today,
    ]);

    $response = getJson("/api/landlord/properties/{$listing->id}/analytics");

    $response->assertStatus(200);

    // Totals should only sum the last 30 days (25 + 15 = 40 views, 10 + 5 = 15 video views, 1 favorite)
    // The metric from 40 days ago must be excluded
    $response->assertJsonPath('data.totals.views', 40)
        ->assertJsonPath('data.totals.video_views', 15)
        ->assertJsonPath('data.totals.favorites', 1);
});
