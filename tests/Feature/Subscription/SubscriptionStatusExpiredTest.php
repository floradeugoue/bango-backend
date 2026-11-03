<?php

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->where('cadence', 'monthly')->first();
});

test('GET /subscriptions/status returns expired subscriptions with state, current_period_end and grace_until', function (): void {
    $landlord = User::factory()->create([
        'name' => 'bailleur_test',
        'display_name' => 'Bailleur Test',
        'badge' => 'none',
        'is_verified' => true,
    ]);

    $endDate = Carbon::create(2026, 11, 19, 14, 0, 0, 'UTC');

    $subscription = Subscription::create([
        'user_id' => $landlord->id,
        'product_id' => $this->landlordProduct->id,
        'price_id' => $this->landlordPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => 10000,
        'currency' => 'XAF',
        'state' => 'expired',
        'current_period_start' => $endDate->copy()->subMonth(),
        'current_period_end' => $endDate,
        'grace_until' => $endDate->copy()->addDays(7),
        'auto_renew' => false,
    ]);

    // Propriété conservée / dépubliée du bailleur
    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'title' => 'Appartement conservé Akwa',
        'status' => ListingStatus::Draft,
    ]);

    Passport::actingAs($landlord);

    // 1. /subscriptions/status doit inclure l'abonnement expiré
    $response = $this->getJson('/api/subscriptions/status');
    $response->assertOk();

    $response->assertJsonPath('status.hasLandlord', false);

    $subscriptions = $response->json('subscriptions');
    expect($subscriptions)->toHaveCount(1)
        ->and($subscriptions[0]['state'])->toBe('expired')
        ->and($subscriptions[0]['badgeType'])->toBe('landlord');

    // Vérifier la présence de current_period_end (camelCase et snake_case)
    expect($subscriptions[0]['currentPeriodEnd'])->toStartWith('2026-11-19')
        ->and($subscriptions[0]['current_period_end'])->toStartWith('2026-11-19');

    // Vérifier grace_until (camelCase et snake_case)
    expect($subscriptions[0]['graceUntil'])->toStartWith('2026-11-26')
        ->and($subscriptions[0]['grace_until'])->toStartWith('2026-11-26');

    // 2. GET /landlord/properties doit autoriser la lecture pour voir et compter ses biens conservés
    $propertiesResponse = $this->getJson('/api/landlord/properties');
    $propertiesResponse->assertOk();
    expect($propertiesResponse->json('data'))->toHaveCount(1)
        ->and($propertiesResponse->json('data.0.title'))->toBe('Appartement conservé Akwa');

    // 3. Publier reste bloqué (403)
    $publishResponse = $this->postJson('/api/listings', [
        'title' => 'Nouvelle annonce interdite',
        'mode' => 'rent',
        'kind' => 'studio',
        'price' => 100000,
        'bedrooms' => 1,
        'showers' => 1,
        'area' => 35,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);
    $publishResponse->assertForbidden();

    // 4. Modifier reste bloqué (403)
    $editResponse = $this->patchJson("/api/listings/{$listing->id}", [
        'title' => 'Titre modifié interdit',
    ]);
    $editResponse->assertForbidden();
});
