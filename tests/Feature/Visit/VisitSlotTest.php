<?php

use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
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

    $this->agent = User::factory()->create([
        'badge' => 'agent',
    ]);

    $this->prospect = User::factory()->create([
        'badge' => 'none',
    ]);

    $this->otherUser = User::factory()->create([
        'badge' => 'none',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'title' => 'Villa Bonapriso',
        'visit_fee' => 5000,
    ]);
});

test('public user can retrieve available slots grid for a listing', function (): void {
    $response = $this->getJson("/api/listings/{$this->listing->id}/slots");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toBeArray()
        ->and(count($data))->toBe(5);

    // Each day has formatted slots
    $firstDay = $data[0];
    expect($firstDay)->toHaveKeys(['id', 'date', 'weekday', 'day', 'available', 'slots']);
});

test('slots in the past or closed are not available', function (): void {
    // Créer un créneau passé
    $pastSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->subDay()->setTime(10, 0),
        'end_at' => now()->subDay()->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    // Créer un créneau fermé
    $closedSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Closed,
    ]);

    $response = $this->getJson("/api/listings/{$this->listing->id}/slots");
    $response->assertOk();

    $allSlots = collect($response->json('data'))->pluck('slots')->flatten(1);

    expect($allSlots->pluck('id'))->not->toContain($pastSlot->id)
        ->and($allSlots->pluck('id'))->not->toContain($closedSlot->id);
});

test('slot with full capacity or active 15min hold is marked unavailable', function (): void {
    $futureSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(14, 0),
        'end_at' => now()->addDays(2)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    // Poser un verrou temporaire de 15 minutes
    VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $futureSlot->id,
        'prospect_id' => $this->otherUser->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::PendingPayment,
        'lock_expires_at' => now()->addMinutes(12),
        'fee_amount' => 5000,
        'currency' => 'XAF',
    ]);

    $response = $this->getJson("/api/listings/{$this->listing->id}/slots");
    $response->assertOk();

    $allSlots = collect($response->json('data'))->pluck('slots')->flatten(1);
    $found = $allSlots->firstWhere('id', $futureSlot->id);

    expect($found)->not->toBeNull()
        ->and($found['available'])->toBeFalse()
        ->and($found['booked_count'])->toBe(1);
});

test('landlord can view, create custom slot and close slot', function (): void {
    Passport::actingAs($this->owner);

    // 1. Consulter les créneaux
    $response = $this->getJson("/api/landlord/properties/{$this->listing->id}/slots");
    $response->assertOk();

    // 2. Créer un créneau personnalisé
    $startAt = now()->addDays(3)->setTime(16, 0);
    $endAt = $startAt->copy()->addMinutes(45);

    $createResponse = $this->postJson("/api/landlord/properties/{$this->listing->id}/slots", [
        'start_at' => $startAt->toIso8601String(),
        'end_at' => $endAt->toIso8601String(),
        'capacity' => 2,
    ]);

    $createResponse->assertCreated();
    $slotId = $createResponse->json('data.id');

    // 3. Fermer le créneau
    $closeResponse = $this->deleteJson("/api/landlord/properties/{$this->listing->id}/slots/{$slotId}");
    $closeResponse->assertOk();
    expect($closeResponse->json('data.status'))->toBe(VisitSlotStatus::Closed->value);
});

test('landlord cannot close a slot with confirmed visits', function (): void {
    Passport::actingAs($this->owner);

    $slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee_amount' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);

    $response = $this->deleteJson("/api/landlord/properties/{$this->listing->id}/slots/{$slot->id}");
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['slot']);
});

test('unauthorized user cannot manage landlord slots', function (): void {
    Passport::actingAs($this->otherUser);

    $response = $this->getJson("/api/landlord/properties/{$this->listing->id}/slots");
    $response->assertStatus(403);

    $createResponse = $this->postJson("/api/landlord/properties/{$this->listing->id}/slots", [
        'start_at' => now()->addDays(2)->toIso8601String(),
        'end_at' => now()->addDays(2)->addMinutes(30)->toIso8601String(),
    ]);
    $createResponse->assertStatus(403);
});

test('mandated agent can manage slots of assigned property', function (): void {
    Passport::actingAs($this->agent);

    $response = $this->getJson("/api/landlord/properties/{$this->listing->id}/slots");
    $response->assertOk();
});
