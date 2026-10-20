<?php

use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('point 44: post listings publishes immediately if owner has active badge, review otherwise, and allows draft', function () {
    // 1. User with active landlord badge
    $badgedOwner = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $resBadged = $this->actingAs($badgedOwner, 'api')->postJson('/api/listings', [
        'title' => 'Bel appartement à Akwa',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 150000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 60,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);
    $resBadged->assertCreated();
    expect($resBadged->json('data.status'))->toBe(ListingStatus::Published->value);

    // 2. Draft creation
    $resDraft = $this->actingAs($badgedOwner, 'api')->postJson('/api/listings', [
        'title' => 'Brouillon villa Bonapriso',
        'mode' => 'rent',
        'kind' => 'villa',
        'price' => 300000,
        'bedrooms' => 3,
        'showers' => 2,
        'area' => 120,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'status' => 'draft',
    ]);
    $resDraft->assertCreated();
    expect($resDraft->json('data.status'))->toBe(ListingStatus::Draft->value);

    // 3. User can switch published listing back to draft
    $listingId = $resBadged->json('data.id');
    $patchRes = $this->actingAs($badgedOwner, 'api')->patchJson("/api/listings/{$listingId}/status", [
        'status' => 'draft',
    ]);
    $patchRes->assertOk();
    expect($patchRes->json('data.status'))->toBe('draft');
});

test('point 44: filters furnished, individual_meter, water_borehole and amenities presence', function () {
    $owner = User::factory()->create(['badge' => 'landlord', 'is_verified' => true]);

    $l1 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
        'amenities' => ['furnished', 'wifi'],
        'city' => 'Douala',
    ]);

    $l2 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
        'amenities' => ['water_borehole', 'parking'],
        'city' => 'Douala',
    ]);

    $l3 = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
        'amenities' => ['individual_meter'],
        'city' => 'Douala',
    ]);

    // Test furnished filter
    $resFurnished = $this->getJson('/api/listings?furnished=1');
    $resFurnished->assertOk();
    $ids = collect($resFurnished->json('data'))->pluck('id')->all();
    expect($ids)->toContain($l1->id);
    expect($ids)->not->toContain($l2->id);

    // Test amenities present in listing resource
    $firstItem = $resFurnished->json('data.0');
    expect($firstItem)->toHaveKey('amenities');
    expect($firstItem['currency'])->toBe('XAF');

    // Test water_borehole filter
    $resWater = $this->getJson('/api/listings?water_borehole=1');
    $resWater->assertOk();
    $idsWater = collect($resWater->json('data'))->pluck('id')->all();
    expect($idsWater)->toContain($l2->id);
    expect($idsWater)->not->toContain($l1->id);

    // Test individual_meter filter
    $resMeter = $this->getJson('/api/listings?individual_meter=1');
    $resMeter->assertOk();
    $idsMeter = collect($resMeter->json('data'))->pluck('id')->all();
    expect($idsMeter)->toContain($l3->id);
    expect($idsMeter)->not->toContain($l1->id);
});

test('point 44: get listings mine and listing comments', function () {
    $owner = User::factory()->create(['badge' => 'landlord', 'is_verified' => true, 'phone' => '+237690000000']);
    $user = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
    ]);

    // GET /listings/mine
    $mineRes = $this->actingAs($owner, 'api')->getJson('/api/listings/mine');
    $mineRes->assertOk();
    expect($mineRes->json('data'))->toHaveCount(1);
    expect($mineRes->json('data.0.id'))->toBe($listing->id);
    expect($mineRes->json('data.0.owner.phone'))->toBe('+237690000000');
    expect($mineRes->json('data.0.owner.meta'))->not->toContain('répond en 2 h');

    // Comments
    $commentRes = $this->actingAs($user, 'api')->postJson("/api/listings/{$listing->id}/comments", [
        'content' => 'Est-ce encore disponible ?',
    ]);
    $commentRes->assertCreated();
    expect($commentRes->json('data.content'))->toBe('Est-ce encore disponible ?');

    $listComments = $this->actingAs($user, 'api')->getJson("/api/listings/{$listing->id}/comments");
    $listComments->assertOk();
    expect($listComments->json('data'))->toHaveCount(1);
});

test('point 44: visit reservation resource exposes neighborhood, cover_image_url and french status', function () {
    $prospect = User::factory()->create();
    $owner = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
    ]);
    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => now()->addDays(1),
        'end_at' => now()->addDays(1)->addHours(1),
        'capacity' => 1,
    ]);

    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee_amount' => 5000,
        'currency' => 'XAF',
    ]);

    $res = $this->actingAs($prospect, 'api')->getJson("/api/visits/{$reservation->id}");
    $res->assertOk();
    $data = $res->json('data');

    expect($data['listing']['neighborhood'])->toBe('Bastos');
    expect($data['listing']['neighbourhood'])->toBe('Bastos');
    expect($data['listing'])->toHaveKey('cover_image_url');
    expect($data['status_label'])->toBe('Confirmée');
});
