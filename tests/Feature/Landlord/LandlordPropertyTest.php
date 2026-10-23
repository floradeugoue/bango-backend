<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Delegation\Delegation;
use App\Models\Kyc\KycDocument;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Kyc\KycSubmittedNotification;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->where('cadence', 'monthly')->first();

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->where('cadence', 'monthly')->first();

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

        return $user;
    };

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

        return $user;
    };
});

test('unauthenticated user cannot access landlord properties', function (): void {
    $response = $this->getJson('/api/landlord/properties');

    $response->assertStatus(401);
});

test('standard user without landlord subscription receives 403 SUBSCRIPTION_REQUIRED', function (): void {
    $user = User::factory()->create([
        'badge' => 'none',
        'is_verified' => true,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/landlord/properties');

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'SUBSCRIPTION_REQUIRED',
        ]);
});

test('landlord with unverified KYC receives 403 KYC_REQUIRED', function (): void {
    $user = ($this->createLandlord)([
        'is_verified' => false,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/landlord/properties');

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'KYC_REQUIRED',
        ]);
});

test('landlord with lapsed verification receives 403 KYC_LAPSED', function (): void {
    $user = ($this->createLandlord)([
        'is_verified' => true,
        'verification_lapsed' => true,
    ]);

    Passport::actingAs($user);

    $response = $this->getJson('/api/landlord/properties');

    $response->assertStatus(403)
        ->assertJson([
            'code' => 'KYC_LAPSED',
        ]);
});

test('landlord can list their own properties with computed counts and metadata', function (): void {
    $landlord = ($this->createLandlord)();
    $otherLandlord = ($this->createLandlord)();

    $myListing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
        'title' => 'Mon bel appartement Bonanjo',
        'views_count' => 45,
    ]);

    $otherListing = Listing::factory()->published()->create([
        'owner_id' => $otherLandlord->id,
        'title' => 'Autre propriété Akwa',
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $myListing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    // Create a requested visit request for myListing
    $prospect = User::factory()->create();
    VisitReservation::create([
        'listing_id' => $myListing->id,
        'slot_id' => $slot->id,
        'owner_id' => $landlord->id,
        'prospect_id' => $prospect->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    Passport::actingAs($landlord);

    $response = $this->getJson('/api/landlord/properties');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'status',
                    'price',
                    'currency',
                    'views_count',
                    'saves_count',
                    'pending_visits_count',
                    'confirmed_visits_count',
                    'created_at',
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
            ],
        ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(1);
    expect($items[0]['id'])->toBe($myListing->id);
    expect($items[0]['pending_visits_count'])->toBe(1);
});

test('landlord can filter properties by status', function (): void {
    $landlord = ($this->createLandlord)();

    Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
        'title' => 'Listing Published',
    ]);

    Listing::factory()->draft()->create([
        'owner_id' => $landlord->id,
        'title' => 'Listing Draft',
    ]);

    Passport::actingAs($landlord);

    $response = $this->getJson('/api/landlord/properties?status=draft');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['status'])->toBe('draft');
});

test('landlord can update status to reserved and published', function (): void {
    $landlord = ($this->createLandlord)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    Passport::actingAs($landlord);

    // published -> reserved
    $response = $this->patchJson("/api/landlord/properties/{$listing->id}/status", [
        'status' => 'reserved',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'reserved');

    expect($listing->fresh()->status)->toBe(ListingStatus::Reserved);

    // reserved -> published
    $response2 = $this->patchJson("/api/landlord/properties/{$listing->id}/status", [
        'status' => 'published',
    ]);

    $response2->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect($listing->fresh()->status)->toBe(ListingStatus::Published);
});

test('updating listing to taken closes future open visit slots and preserves history', function (): void {
    $landlord = ($this->createLandlord)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    // Create a future open slot
    $openSlot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $pastSlot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->subDays(2)->setTime(10, 0),
        'end_at' => now()->subDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Closed,
    ]);

    // Create a confirmed past reservation
    $prospect = User::factory()->create();
    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $pastSlot->id,
        'owner_id' => $landlord->id,
        'prospect_id' => $prospect->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    Passport::actingAs($landlord);

    $response = $this->patchJson("/api/landlord/properties/{$listing->id}/status", [
        'status' => 'taken',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'taken');

    // Future open slot is now closed
    expect($openSlot->fresh()->status)->toBe(VisitSlotStatus::Closed);

    // Past confirmed reservation is fully preserved
    expect($reservation->fresh()->status)->toBe(VisitReservationStatus::Confirmed);
});

test('user cannot update status of property owned by someone else', function (): void {
    $landlordA = ($this->createLandlord)();
    $landlordB = ($this->createLandlord)();

    $listingA = Listing::factory()->published()->create([
        'owner_id' => $landlordA->id,
    ]);

    Passport::actingAs($landlordB);

    $response = $this->patchJson("/api/landlord/properties/{$listingA->id}/status", [
        'status' => 'reserved',
    ]);

    $response->assertStatus(403);
});

test('landlord can batch update visit slots using schedule rules', function (): void {
    $landlord = ($this->createLandlord)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    Passport::actingAs($landlord);

    $payload = [
        'slot_duration_minutes' => 30,
        'horizon_days' => 7,
        'days' => [
            [
                'day' => 1, // Monday
                'from' => '09:00',
                'to' => '12:00',
            ],
            [
                'day' => 3, // Wednesday
                'from' => '14:00',
                'to' => '17:00',
            ],
        ],
    ];

    $response = $this->putJson("/api/landlord/properties/{$listing->id}/slots", $payload);

    $response->assertOk()
        ->assertJsonStructure([
            'message',
            'data' => [
                'listing_id',
                'total_slots',
                'slots',
            ],
        ]);

    $slotsCount = VisitSlot::where('listing_id', $listing->id)->count();
    expect($slotsCount)->toBeGreaterThan(0);
});

test('agent with visits capability can update slots but agent without visits cannot', function (): void {
    $landlord = ($this->createLandlord)();
    $agentWithVisits = ($this->createAgent)();
    $agentWithoutVisits = ($this->createAgent)();

    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    // Active delegation with visits
    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agentWithVisits->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(12),
    ]);

    // Active delegation with only edit (no visits)
    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agentWithoutVisits->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Edit->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(12),
    ]);

    // Agent without visits -> 403
    Passport::actingAs($agentWithoutVisits);
    $resFail = $this->putJson("/api/landlord/properties/{$listing->id}/slots", [
        'days' => [
            ['day' => 2, 'from' => '10:00', 'to' => '11:00'],
        ],
    ]);
    $resFail->assertStatus(403);

    // Agent with visits -> 200
    Passport::actingAs($agentWithVisits);
    $resSuccess = $this->putJson("/api/landlord/properties/{$listing->id}/slots", [
        'days' => [
            ['day' => 2, 'from' => '10:00', 'to' => '11:00'],
        ],
    ]);
    $resSuccess->assertOk();
});

test('landlord can retrieve incoming visit requests with pagination and filters', function (): void {
    $landlord = ($this->createLandlord)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(1)->setTime(11, 0),
        'end_at' => now()->addDays(1)->setTime(11, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $prospect = User::factory()->create();

    VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'owner_id' => $landlord->id,
        'prospect_id' => $prospect->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    Passport::actingAs($landlord);

    $response = $this->getJson('/api/landlord/visit-requests?status=requested');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'status',
                    'fee_amount',
                    'prospect' => ['id', 'name'],
                    'listing' => ['id', 'title'],
                ],
            ],
            'meta',
        ]);

    expect($response->json('data'))->toHaveCount(1);
});

test('landlord can retrieve visit requests even if the listing has been soft-deleted (BL-06)', function (): void {
    $landlord = ($this->createLandlord)();

    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(1)->setTime(14, 0),
        'end_at' => now()->addDays(1)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $prospect = User::factory()->create();

    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'owner_id' => $landlord->id,
        'prospect_id' => $prospect->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    // Soft delete the listing
    $listing->delete();
    expect($listing->trashed())->toBeTrue();

    Passport::actingAs($landlord);

    $response = $this->getJson('/api/landlord/visit-requests');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($reservation->id);
    expect($response->json('data.0.listing.id'))->toBe($listing->id);
    expect($response->json('data.0.listing.title'))->toBe($listing->title);
});

test('user can submit KYC documents securely to private storage', function (): void {
    Storage::fake('local');
    Notification::fake();

    $user = User::factory()->create([
        'is_verified' => false,
    ]);

    Passport::actingAs($user);

    $frontFile = UploadedFile::fake()->create('id_front.jpg', 500, 'image/jpeg');
    $backFile = UploadedFile::fake()->create('id_back.jpg', 500, 'image/jpeg');
    $selfieFile = UploadedFile::fake()->create('selfie.jpg', 600, 'image/jpeg');

    $response = $this->postJson('/api/landlord/kyc/submit', [
        'id_type' => 'cni',
        'id_number' => '1234567890',
        'identity_front' => $frontFile,
        'identity_back' => $backFile,
        'selfie' => $selfieFile,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'id_type',
                'documents' => [
                    '*' => ['id', 'kind', 'original_name', 'mime_type', 'file_size'],
                ],
            ],
        ]);

    expect($response->json('data.status'))->toBe('pending');
    expect($response->json('data.documents'))->toHaveCount(3);

    // Verify files were stored on private disk
    $docs = $response->json('data.documents');
    foreach ($docs as $doc) {
        $path = KycDocument::find($doc['id'])->file_path;
        Storage::disk('local')->assertExists($path);
    }

    // Verify notification was sent
    Notification::assertSentTo($user, KycSubmittedNotification::class);

    // Verify submitting KYC does NOT automatically verify user or activate badge
    expect($user->fresh()->is_verified)->toBeFalse();
});
