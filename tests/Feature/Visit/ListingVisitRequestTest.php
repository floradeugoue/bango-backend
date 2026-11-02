<?php

use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\ListingVisitRequest;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Visit\ListingVisitRequestedNotification;
use App\Notifications\Visit\ListingVisitSlotChosenNotification;
use App\Services\Boost\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    // 4. Test updating uses_agenda via PATCH /listings/{id}
    Passport::actingAs($this->owner);
    $patchRes = $this->patchJson("/api/listings/{$this->listing->id}", [
        'uses_agenda' => false,
    ]);
    $patchRes->assertOk();
    expect($patchRes->json('data.uses_agenda'))->toBeFalse();
    expect($this->listing->fresh()->uses_agenda)->toBeFalse();

    // Re-enabling uses_agenda via PATCH /listings/{id}
    $patchRes2 = $this->patchJson("/api/listings/{$this->listing->id}", [
        'uses_agenda' => true,
    ]);
    $patchRes2->assertOk();
    expect($patchRes2->json('data.uses_agenda'))->toBeTrue();
    expect($this->listing->fresh()->uses_agenda)->toBeTrue();

    // 5. Test updating uses_agenda via landlord settings (PUT /api/profile)
    $profileRes = $this->putJson('/api/profile', [
        'uses_agenda' => false,
    ]);
    $profileRes->assertOk();
    expect($profileRes->json('user.uses_agenda'))->toBeFalse();
    expect($this->owner->fresh()->uses_agenda)->toBeFalse();

    // 6. Test updating uses_agenda via PUT /api/landlord/properties/{id}/slots
    $slotsUpdateRes = $this->putJson("/api/landlord/properties/{$this->listing->id}/slots", [
        'uses_agenda' => false,
    ]);
    $slotsUpdateRes->assertOk();
    expect($this->listing->fresh()->uses_agenda)->toBeFalse();
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
        'data' => [
            'id',
            'listing_id',
            'prospect_id',
            'status',
            'proposals',
            'expires_at',
        ],
    ]);

    expect($response->json('data.status'))->toBe('pending')
        ->and(count($response->json('data.proposals')))->toBe(2)
        ->and($response->json('data.message'))->toBe('Bonjour, je suis très intéressé par le studio.')
        ->and($response->json('data.visit_reservation_id'))->toBeNull();

    // Vérifier que expires_at est fixé à 24h
    $expiresAt = Carbon\Carbon::parse($response->json('data.expires_at'));
    expect(round(abs(now()->diffInRealHours($expiresAt))))->toBe(24.0);

    $this->assertDatabaseHas('listing_visit_requests', [
        'id' => $response->json('data.id'),
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
    expect($landlordRes->json('data.viewed_by_landlord_at'))->not->toBeNull();

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

test('IM-22 : GET /landlord/listing-visit-requests liste les demandes pour le bailleur et agent avec prospect, bien et proposals', function (): void {
    Notification::fake();

    $d1 = now()->addDays(2)->setTime(10, 0)->toIso8601String();
    $d2 = now()->addDays(3)->setTime(15, 30)->toIso8601String();

    // 1. Soumission d'une demande par le prospect avec notification au bailleur
    Passport::actingAs($this->prospect);
    $postRes = $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [$d1, $d2],
        'message' => 'Propositions créneaux week-end',
    ]);
    $postRes->assertStatus(201);
    $requestId = $postRes->json('data.id');

    Notification::assertSentTo(
        $this->owner,
        ListingVisitRequestedNotification::class
    );

    // 2. Le bailleur consulte la liste via GET /api/landlord/listing-visit-requests?status=pending
    Passport::actingAs($this->owner);
    $listRes = $this->getJson('/api/landlord/listing-visit-requests?status=pending');
    $listRes->assertOk();

    $items = $listRes->json('data');
    expect($items)->toHaveCount(1);
    $item = $items[0];

    expect($item['id'])->toBe($requestId)
        ->and($item['status'])->toBe('pending')
        ->and($item['prospect'])->not->toBeNull()
        ->and($item['prospect']['name'])->toBe($this->prospect->name)
        ->and($item['listing'])->not->toBeNull()
        ->and($item['listing']['title'])->toBe($this->listing->title)
        ->and($item['proposals'])->toHaveCount(2);

    // 3. Le bailleur choisit le créneau -> notification envoyée au prospect
    $chooseRes = $this->postJson("/api/landlord/visit-requests/{$requestId}/choose", [
        'proposal_index' => 0,
    ]);
    $chooseRes->assertOk();

    Notification::assertSentTo(
        $this->prospect,
        ListingVisitSlotChosenNotification::class
    );

    // 4. La demande n'est plus pending
    $listPendingAfter = $this->getJson('/api/landlord/listing-visit-requests?status=pending');
    $listPendingAfter->assertOk();
    expect($listPendingAfter->json('data'))->toHaveCount(0);
});

test('IM-23 : GET /visits/requests liste les demandes du prospect et POST /visits/{id}/pay règle la réservation au crédit BANGO ou mobile money', function (): void {
    Notification::fake();

    $d1 = now()->addDays(2)->setTime(10, 0)->toIso8601String();

    // 1. Soumission d'une demande par le prospect
    Passport::actingAs($this->prospect);
    $postRes = $this->postJson("/api/listings/{$this->listing->id}/visit-requests", [
        'proposals' => [$d1],
        'message' => 'Demande pour visiter',
    ])->assertStatus(201);
    $requestId = $postRes->json('data.id');

    // 2. Le prospect consulte ses demandes via GET /api/visits/requests
    $mineRes = $this->getJson('/api/visits/requests')->assertOk();
    expect($mineRes->json('data'))->toHaveCount(1)
        ->and($mineRes->json('data.0.id'))->toBe($requestId);

    // 3. Le bailleur choisit le créneau
    Passport::actingAs($this->owner);
    $chooseRes = $this->postJson("/api/landlord/visit-requests/{$requestId}/choose", [
        'proposal_index' => 0,
    ])->assertOk();

    $reservationId = $chooseRes->json('data.reservation_id') ?? $chooseRes->json('reservation.id');
    expect($reservationId)->not->toBeNull();

    // 4. Le prospect consulte ses demandes et voit la réservation rattachée
    Passport::actingAs($this->prospect);
    $mineAfterChoose = $this->getJson('/api/visits/requests')->assertOk();
    expect($mineAfterChoose->json('data.0.reservation_id'))->toBe($reservationId)
        ->and($mineAfterChoose->json('data.0.status'))->toBe('chosen');

    // 5. Créditer le compte du prospect pour payer par crédit BANGO
    app(CreditWalletService::class)->credit(
        account: $this->prospect,
        amount: 3000,
        source: 'deposit',
        description: 'Recharge test'
    );

    // 6. Le prospect règle la réservation via POST /api/visits/{id}/pay
    $payRes = $this->postJson("/api/visits/{$reservationId}/pay", [
        'payment_method' => 'credit',
    ])->assertOk();

    expect($payRes->json('data.id'))->toBe($reservationId)
        ->and($payRes->json('data.status'))->toBe('requested')
        ->and($payRes->json('quote.amount'))->toEqual(3000.0);

    // La réservation en base est désormais payée
    $reservation = VisitReservation::find($reservationId);
    expect($reservation->paid_at)->not->toBeNull();
});
