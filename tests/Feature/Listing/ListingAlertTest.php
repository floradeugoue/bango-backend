<?php

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Events\Listing\ListingPublished;
use App\Listeners\Listing\NotifyMatchingHousingSearches;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingAlert;
use App\Models\Notification\DeviceToken;
use App\Models\User;
use App\Notifications\Listing\NewMatchingListingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'name' => 'Recherchant Alertes',
        'is_verified' => true,
    ]);

    $this->otherUser = User::factory()->create([
        'name' => 'Autre Utilisateur',
    ]);

    $this->owner = User::factory()->create([
        'name' => 'Bailleur Vérifié',
        'is_verified' => true,
    ]);
});

test('user can create, list, update and delete listing alerts', function (): void {
    Passport::actingAs($this->user);

    // 1. POST /api/listings/alerts
    $payload = [
        'name' => 'Mon alerte Bonapriso',
        'mode' => 'rent',
        'country_code' => 'cm',
        'city' => 'Douala',
        'neighbourhoods' => ['Bonapriso', 'Akwa'],
        'kinds' => ['flat', 'studio'],
        'budget_min' => 100000,
        'budget_max' => 350000,
        'bedrooms' => [2, 3],
        'showers' => [1, 2],
        'area_min' => 50,
        'area_max' => 150,
        'amenities' => ['climatisation', 'wifi'],
        'verified_only' => true,
        'agent_visited_only' => false,
        'utilities_included_only' => false,
        'frequency' => 'instant',
        'push' => true,
        'email' => false,
    ];

    $createRes = $this->postJson('/api/listings/alerts', $payload);
    $createRes->assertCreated();
    $createRes->assertJsonStructure([
        'id',
        'name',
        'mode',
        'country_code',
        'city',
        'neighbourhoods',
        'kinds',
        'budget_min',
        'budget_max',
        'bedrooms',
        'showers',
        'area_min',
        'area_max',
        'amenities',
        'verified_only',
        'frequency',
        'push',
        'email',
    ]);

    $alertId = $createRes->json('id');
    expect($createRes->json('country_code'))->toBe('CM')
        ->and($createRes->json('neighbourhoods'))->toBe(['Bonapriso', 'Akwa'])
        ->and((float) $createRes->json('budget_max'))->toBe(350000.0);

    // 2. GET /api/listings/alerts
    $listRes = $this->getJson('/api/listings/alerts');
    $listRes->assertOk();
    expect(count($listRes->json('data')))->toBe(1)
        ->and($listRes->json('data.0.id'))->toBe($alertId);

    // Other user cannot see my alerts
    Passport::actingAs($this->otherUser);
    $otherList = $this->getJson('/api/listings/alerts');
    $otherList->assertOk();
    expect(count($otherList->json('data')))->toBe(0);

    // 3. PATCH /api/listings/alerts/{id}
    Passport::actingAs($this->user);
    $updateRes = $this->patchJson("/api/listings/alerts/{$alertId}", [
        'budget_max' => 400000,
        'name' => 'Alerte Bonapriso Modifiée',
    ]);
    $updateRes->assertOk();
    expect((float) $updateRes->json('budget_max'))->toBe(400000.0)
        ->and($updateRes->json('name'))->toBe('Alerte Bonapriso Modifiée');

    // 4. DELETE /api/listings/alerts/{id}
    $deleteRes = $this->deleteJson("/api/listings/alerts/{$alertId}");
    $deleteRes->assertOk();

    $this->assertDatabaseMissing('listing_alerts', ['id' => $alertId]);
});

test('publishing a matching listing triggers notification with listing_id and push', function (): void {
    Notification::fake();

    // Create device token for user to test push dispatch
    DeviceToken::create([
        'user_id' => $this->user->id,
        'token' => 'fake_fcm_token_123',
        'platform' => 'android',
        'device_id' => 'device_abc',
        'is_active' => true,
    ]);

    // Create alert for user
    $alert = ListingAlert::create([
        'user_id' => $this->user->id,
        'name' => 'Recherche 3 pièces Bonanjo',
        'mode' => 'rent',
        'city' => 'Douala',
        'neighbourhoods' => ['Bonanjo'],
        'kinds' => ['flat'],
        'budget_min' => 200000,
        'budget_max' => 500000,
        'bedrooms' => [3],
        'verified_only' => true,
        'frequency' => 'instant',
        'push' => true,
    ]);

    // Matching listing
    $matchingListing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Superbe T3 vue port',
        'mode' => ListingMode::Rent,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
        'price' => 350000,
        'currency' => 'XAF',
        'bedrooms' => 3,
        'published_at' => now(),
    ]);

    $listener = new NotifyMatchingHousingSearches;
    $listener->handle(new ListingPublished($matchingListing));

    // Notification is sent to user
    Notification::assertSentTo(
        $this->user,
        NewMatchingListingNotification::class,
        function ($notification) use ($matchingListing) {
            return $notification->listing->id === $matchingListing->id;
        }
    );

    // Alert last_triggered_at updated
    $alert->refresh();
    expect($alert->last_triggered_at)->not->toBeNull();
});

test('publishing an unmatching listing does not notify user', function (): void {
    Notification::fake();

    ListingAlert::create([
        'user_id' => $this->user->id,
        'city' => 'Yaoundé',
        'frequency' => 'instant',
    ]);

    $doualaListing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Studio Douala',
        'status' => ListingStatus::Published,
        'city' => 'Douala',
        'published_at' => now(),
    ]);

    $listener = new NotifyMatchingHousingSearches;
    $listener->handle(new ListingPublished($doualaListing));

    Notification::assertNotSentTo($this->user, NewMatchingListingNotification::class);
});
