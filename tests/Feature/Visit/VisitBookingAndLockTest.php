<?php

use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->prospect = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237699112233',
    ]);

    $this->otherProspect = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237677889900',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement Bonanjo',
        'visit_fee' => 5000,
        'currency' => 'XAF',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);
});

test('unauthenticated user cannot book a visit', function (): void {
    $response = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
    ]);

    $response->assertUnauthorized();
});

test('owner cannot book a visit on their own listing', function (): void {
    Passport::actingAs($this->owner);

    $response = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['listing']);
});

test('slot must belong to the requested listing', function (): void {
    Passport::actingAs($this->prospect);

    $otherListing = Listing::factory()->published()->create();
    $otherSlot = VisitSlot::create([
        'listing_id' => $otherListing->id,
        'owner_id' => $otherListing->owner_id,
        'start_at' => now()->addDays(2)->setTime(14, 0),
        'end_at' => now()->addDays(2)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $response = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $otherSlot->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['slot_id']);
});

test('prospect acquires 15-minute lock and initializes PAY transaction', function (): void {
    Passport::actingAs($this->prospect);

    $response = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
        'message' => 'Bonjour, je souhaite visiter cet appartement.',
        'channel' => 'mobile_money',
        'operator' => 'orange',
        'phone_number' => '+237699112233',
    ]);

    $response->assertCreated();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::PendingPayment->value)
        ->and((float) $data['fee_amount'])->toEqual(5000.0)
        ->and($data['currency'])->toBe('XAF')
        ->and($data['is_lock_active'])->toBeTrue()
        ->and($data['lock_expires_at'])->not->toBeNull();

    // Vérifier la réservation en base
    $reservation = VisitReservation::find($data['id']);
    expect($reservation)->not->toBeNull()
        ->and($reservation->prospect_id)->toBe($this->prospect->id)
        ->and($reservation->slot_id)->toBe($this->slot->id)
        ->and($reservation->lock_expires_at->diffInMinutes(now()))->toBeLessThanOrEqual(15);

    // Vérifier la transaction PAY rattachée
    $transaction = PaymentTransaction::find($reservation->payment_transaction_id);
    expect($transaction)->not->toBeNull()
        ->and($transaction->service_type)->toBe(PaymentServiceType::Visit)
        ->and($transaction->payable_type)->toBe(VisitReservation::class)
        ->and($transaction->payable_id)->toBe($reservation->id)
        ->and((float) $transaction->amount)->toBe(5000.0)
        ->and($transaction->status)->toBe(PaymentStatus::Pending);
});

test('concurrent booking attempt on locked slot is rejected', function (): void {
    // 1. Premier prospect réserve et pose le verrou
    Passport::actingAs($this->prospect);
    $response1 = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
    ]);
    $response1->assertCreated();

    // 2. Second prospect tente de réserver le même créneau alors que le verrou est actif
    Passport::actingAs($this->otherProspect);
    $response2 = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
    ]);

    $response2->assertStatus(422)
        ->assertJsonValidationErrors(['slot_id']);
});

test('same prospect cannot create double pending reservations on the same slot', function (): void {
    Passport::actingAs($this->prospect);

    $response1 = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
    ]);
    $response1->assertCreated();

    $response2 = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
    ]);

    $response2->assertStatus(422)
        ->assertJsonValidationErrors(['slot_id']);
});

test('free visit with zero fee confirms immediately without payment lock', function (): void {
    Passport::actingAs($this->prospect);

    $freeListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'visit_fee' => 0,
    ]);

    $freeSlot = VisitSlot::create([
        'listing_id' => $freeListing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(11, 0),
        'end_at' => now()->addDays(2)->setTime(11, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $response = $this->postJson("/api/listings/{$freeListing->id}/visits", [
        'slot_id' => $freeSlot->id,
    ]);

    $response->assertCreated();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Requested->value)
        ->and((float) $data['fee_amount'])->toEqual(0.0)
        ->and($data['lock_expires_at'])->toBeNull()
        ->and($response->json('transaction'))->toBeNull();
});
