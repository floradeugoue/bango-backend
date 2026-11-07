<?php

namespace Tests\Feature\Admin\Listing;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.listings.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->sessionId,
        'user_id' => $this->operator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

test('listings list handles q search on reference and owner, sorting, multi-status, and tab counts', function () {
    $owner1 = User::factory()->create([
        'name' => 'Marc Proprietaire',
        'display_name' => 'Marc Immo',
        'handle' => 'marcimmo',
        'is_verified' => true,
        'badge' => 'landlord',
    ]);
    $owner2 = User::factory()->create([
        'name' => 'Sarah Agent',
        'handle' => 'sarahagent',
        'is_verified' => true,
        'badge' => 'agent',
    ]);

    $l1 = Listing::factory()->create([
        'owner_id' => $owner1->id,
        'created_by_id' => $owner1->id,
        'title' => 'Superbe Studio Akwa',
        'description' => 'Lumineux avec balcon',
        'price' => 150000,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'kind' => ListingKind::Studio,
        'status' => ListingStatus::Published,
    ]);

    $l2 = Listing::factory()->create([
        'owner_id' => $owner2->id,
        'created_by_id' => $owner2->id,
        'title' => 'Villa Bonapriso',
        'description' => 'Piscine et jardin',
        'price' => 500000,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'kind' => ListingKind::Villa,
        'status' => ListingStatus::Review,
    ]);

    // 1. Search by reference prefix or title
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings?q='.urlencode($l1->short_reference));

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($l1->id)
        ->and($data[0]['reference'])->toBe($l1->reference)
        ->and($response->json('meta.counts'))->toBeArray();

    // 2. Multi-status filter and counts
    $multiResp = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings?status[]=published&status[]=review');
    $multiResp->assertOk();
    expect($multiResp->json('meta.counts.published'))->toBeGreaterThanOrEqual(1)
        ->and($multiResp->json('meta.counts.review'))->toBeGreaterThanOrEqual(1);

    // 3. Sort by price
    $sortResp = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings?sort=price_desc');
    $sortResp->assertOk();
    $sortedItems = $sortResp->json('data');
    expect((float) $sortedItems[0]['price'])->toBeGreaterThanOrEqual((float) $sortedItems[1]['price']);
});

test('listings map returns aggregated city centroids by default and detailed markers when requested', function () {
    $owner = User::factory()->create();

    Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Appartement 1',
        'price' => 100000,
        'city' => 'Douala',
        'lat' => 4.0511,
        'lng' => 9.7679,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
    ]);

    Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Appartement 2',
        'price' => 120000,
        'city' => 'Douala',
        'lat' => 4.0600,
        'lng' => 9.7500,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Review,
    ]);

    Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Appartement Yaounde',
        'price' => 90000,
        'city' => 'Yaoundé',
        'lat' => 3.8480,
        'lng' => 11.5021,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
    ]);

    // Aggregated by default
    $resp = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings/map');

    $resp->assertOk();
    $cities = $resp->json('data');
    expect($cities)->toBeArray()
        ->and(collect($cities)->firstWhere('city', 'Douala')['total'])->toBe(2)
        ->and(collect($cities)->firstWhere('city', 'Yaoundé')['total'])->toBe(1);

    // Detailed markers
    $detailResp = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings/map?detailed=true');
    $detailResp->assertOk();
    expect($detailResp->json('data'))->toHaveCount(3);
});

use App\Enums\Visit\VisitSlotStatus;
use App\Models\Visit\VisitSlot;

test('visits list handles q search on BNG-V reference, SLA 18h calculation, and overdue settlement', function () {
    $prospect = User::factory()->create(['name' => 'Paul Visiteur', 'email' => 'paul.visiteur@bango.app']);
    $landlord = User::factory()->create(['name' => 'Bailleur Joseph']);

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'created_by_id' => $landlord->id,
        'title' => 'Studio Meublé Bastos',
        'price' => 200000,
        'city' => 'Yaoundé',
        'kind' => ListingKind::Studio,
        'status' => ListingStatus::Published,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(2)->setHour(10),
        'end_at' => now()->addDays(2)->setHour(11),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    $v1 = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $landlord->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XAF',
        'payment_reference' => 'VISIT-CREDIT-TEST001',
    ]);
    $v1->timestamps = false;
    $v1->created_at = now()->subHours(25);
    $v1->save();

    expect($v1->reference)->toStartWith('BNG-V-');

    // 1. Search by reference
    $resp = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/listings/visits?q='.urlencode($v1->short_reference));
    $resp->assertOk();
    $data = $resp->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($v1->id)
        ->and($data[0]['is_overdue_sla_18h'])->toBeTrue()
        ->and($resp->json('meta.counts'))->toBeArray();

    // 2. Settle overdue visits
    $settleResp = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson('/api/admin/listings/visits/settle-overdue');

    $settleResp->assertOk();
    expect($settleResp->json('settled_count'))->toBeGreaterThanOrEqual(1);

    $v1->refresh();
    expect($v1->status)->toBe(VisitReservationStatus::Declined);
});
