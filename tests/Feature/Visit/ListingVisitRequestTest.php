<?php

use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\ListingVisitRequest;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
        'uses_agenda' => true,
    ]);

    $this->prospect = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237690001122',
    ]);

    $this->otherUser = User::factory()->create([
        'badge' => 'none',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Studio Akwa',
        'visit_fee' => 3000,
        'uses_agenda' => null, // falls back to owner
    ]);
});

test('uses_agenda is rendered in ListingResource and determines default slots generation', function (): void {
    // 1. By default, uses_agenda is true
    $resTrue = $this->getJson("/api/listings/{$this->listing->id}");
    $resTrue->assertOk();
    expect($resTrue->json('data.uses_agenda'))->toBeTrue();

    // GET /slots should create default slots when uses_agenda is true
    $slotsRes = $this->getJson("/api/listings/{$this->listing->id}/slots");
    $slotsRes->assertOk();
    expect(VisitSlot::where('listing_id', $this->listing->id)->count())->toBeGreaterThan(0);

    // 2. Set uses_agenda to false on listing
    $noAgendaListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appart sans agenda',
        'uses_agenda' => false,
    ]);

    $resFalse = $this->getJson("/api/listings/{$noAgendaListing->id}");
    $resFalse->assertOk();
    expect($resFalse->json('data.uses_agenda'))->toBeFalse();

    // GET /slots for no-agenda listing should NOT generate default slots
    $noSlotsRes = $this->getJson("/api/listings/{$noAgendaListing->id}/slots");
    $noSlotsRes->assertOk();
    expect(VisitSlot::where('listing_id', $noAgendaListing->id)->count())->toBe(0);

    // 3. Fallback to owner uses_agenda when listing uses_agenda is null
    $this->owner->update(['uses_agenda' => false]);
    $listingWithNullAgenda = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'uses_agenda' => null,
    ]);
    expect($listingWithNullAgenda->usesAgenda())->toBeFalse();

    $resNull = $this->getJson("/api/listings/{$listingWithNullAgenda->id}");
    $resNull->assertOk();
    expect($resNull->json('data.uses_agenda'))->toBeFalse();
});

test('prospect can submit visit request with 1 to 3 proposals without payment (IM-22)', function (): void {
    Passport::actingAs($this->prospect);

    $p1 = now()->addDays(2)->setTime(10, 0)->toIso8601String();
    $p2 = now()->addDays(3)->setTime(14, 30)->toIso8601String();

    $response = $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [$p1, $p2],
        'message' => 'Bonjour, je suis très intéressé par le studio.',
    ]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'id',
        'listing_id',
        'prospect_id',
        'status',
        'proposals',
        'expires_at',
    ]);

    expect($response->json('status'))->toBe('pending')
        ->and(count($response->json('proposals')))->toBe(2)
        ->and($response->json('message'))->toBe('Bonjour, je suis très intéressé par le studio.')
        ->and($response->json('visit_reservation_id'))->toBeNull();

    $this->assertDatabaseHas('listing_visit_requests', [
        'id' => $response->json('id'),
        'listing_id' => $this->listing->id,
        'prospect_id' => $this->prospect->id,
        'status' => 'pending',
    ]);
});

test('visit request validation requires 1 to 3 future dates and rejects owner', function (): void {
    Passport::actingAs($this->prospect);

    // Empty proposals
    $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [],
    ])->assertStatus(422)->assertJsonValidationErrors(['proposals']);

    // More than 3 proposals
    $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [
            now()->addDay()->toIso8601String(),
            now()->addDays(2)->toIso8601String(),
            now()->addDays(3)->toIso8601String(),
            now()->addDays(4)->toIso8601String(),
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['proposals']);

    // Past date proposal
    $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [
            now()->subHour()->toIso8601String(),
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['proposals.0']);

    // Owner cannot request visit on own listing
    Passport::actingAs($this->owner);
    $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [
            now()->addDay()->toIso8601String(),
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['listing']);
});

test('prospect and landlord can view visit request and landlord viewing sets viewed_by_landlord_at', function (): void {
    $p1 = now()->addDays(2)->setTime(10, 0)->toIso8601String();

    $request = ListingVisitRequest::create([
        'listing_id' => $this->listing->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'proposals' => [$p1],
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    // 1. Prospect views request -> viewed_by_landlord_at remains null
    Passport::actingAs($this->prospect);
    $prospectRes = $this->getJson("/api/visits/requests/{$request->id}");
    $prospectRes->assertOk();
    expect($prospectRes->json('viewed_by_landlord_at'))->toBeNull();

    // 2. Unauthorized user cannot view request
    Passport::actingAs($this->otherUser);
    $this->getJson("/api/visits/requests/{$request->id}")->assertForbidden();

    // 3. Landlord views request -> viewed_by_landlord_at is updated
    Passport::actingAs($this->owner);
    $landlordRes = $this->getJson("/api/visits/requests/{$request->id}");
    $landlordRes->assertOk();
    expect($landlordRes->json('viewed_by_landlord_at'))->not->toBeNull();

    $request->refresh();
    expect($request->viewed_by_landlord_at)->not->toBeNull();
});

test('prospect can cancel their visit request', function (): void {
    $request = ListingVisitRequest::create([
        'listing_id' => $this->listing->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'proposals' => [now()->addDays(2)->toIso8601String()],
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);

    // Another user cannot cancel
    Passport::actingAs($this->otherUser);
    $this->postJson("/api/visits/requests/{$request->id}/cancel")->assertForbidden();

    // Prospect can cancel
    Passport::actingAs($this->prospect);
    $res = $this->postJson("/api/visits/requests/{$request->id}/cancel");
    $res->assertOk();
    expect($res->json('data.status'))->toBe('cancelled')
        ->and($res->json('data.cancelled_at'))->not->toBeNull();

    $request->refresh();
    expect($request->status)->toBe('cancelled')
        ->and($request->cancelled_at)->not->toBeNull();
});

test('landlord chooses proposal index creating a reservation pending payment with 15min lock (IM-22 / IM-23)', function (): void {
    $d1 = now()->addDays(2)->setTime(10, 0)->toIso8601String();
    $d2 = now()->addDays(3)->setTime(15, 30)->toIso8601String();

    $request = ListingVisitRequest::create([
        'listing_id' => $this->listing->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'proposals' => [$d1, $d2],
        'status' => 'pending',
        'message' => 'Disponible le matin surtout.',
        'expires_at' => now()->addDays(7),
    ]);

    Passport::actingAs($this->owner);

    // Choose proposal index 1 ($d2)
    $response = $this->postJson("/api/landlord/visit-requests/{$request->id}/choose", [
        'proposal_index' => 1,
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'message',
        'data',
        'reservation',
        'quote',
        'transaction',
    ]);

    $request->refresh();
    expect($request->status)->toBe('chosen')
        ->and($request->chosen_proposal_index)->toBe(1)
        ->and($request->chosen_slot_at)->not->toBeNull()
        ->and($request->visit_reservation_id)->not->toBeNull();

    $reservation = VisitReservation::find($request->visit_reservation_id);
    expect($reservation)->not->toBeNull()
        ->and($reservation->status->value)->toBe('pending_payment')
        ->and($reservation->lock_expires_at)->not->toBeNull()
        ->and($reservation->prospect_id)->toBe($this->prospect->id)
        ->and($reservation->owner_id)->toBe($this->owner->id);

    // A VisitSlot was created for the listing
    $slot = VisitSlot::find($reservation->slot_id);
    expect($slot)->not->toBeNull()
        ->and($slot->listing_id)->toBe($this->listing->id)
        ->and($slot->start_at->toIso8601String())->toBe(Carbon\Carbon::parse($d2)->toIso8601String());
});
