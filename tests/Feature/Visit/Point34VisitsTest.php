<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Visit\VisitRequestedNotification;
use App\Services\Boost\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();

    $this->owner = User::factory()->create(['badge' => 'landlord']);
    $this->agent = User::factory()->create(['badge' => 'agent']);
    $this->prospect = User::factory()->create(['phone' => '+237699112233']);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement Point34',
        'visit_fee' => 3000,
        'currency' => 'XAF',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(14, 0),
        'end_at' => now()->addDays(2)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);
});

test('free or paid visit creates reservation in requested status (BL-07 18h SLA) and notifies landlord', function (): void {
    $freeListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'visit_fee' => 0,
    ]);
    $freeSlot = VisitSlot::create([
        'listing_id' => $freeListing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(15, 0),
        'end_at' => now()->addDays(2)->setTime(15, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    Passport::actingAs($this->prospect);
    $response = $this->postJson("/api/listings/{$freeListing->id}/visits", [
        'slot_id' => $freeSlot->id,
    ]);

    $response->assertCreated();
    expect($response->json('data.status'))->toBe(VisitReservationStatus::Requested->value);

    Notification::assertSentTo($this->owner, VisitRequestedNotification::class);
});

test('booking visit with credit_wallet channel succeeds and debits credits', function (): void {
    app(CreditWalletService::class)->credit(
        account: $this->prospect,
        amount: 5000,
        description: 'Recharge test'
    );

    Passport::actingAs($this->prospect);
    $response = $this->postJson("/api/listings/{$this->listing->id}/visits", [
        'slot_id' => $this->slot->id,
        'channel' => 'credit_wallet',
    ]);

    $response->assertCreated();
    expect($response->json('data.status'))->toBe(VisitReservationStatus::Requested->value)
        ->and($response->json('data.paid_at'))->not->toBeNull();

    Notification::assertSentTo($this->owner, VisitRequestedNotification::class);
});

test('delegated agent with visits capability sees visit requests on delegated listings', function (): void {
    Delegation::create([
        'bailleur_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'listing_id' => $this->listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
        'answered_at' => now(),
    ]);

    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Requested,
        'fee_amount' => 3000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);

    Passport::actingAs($this->agent);
    $response = $this->getJson('/api/landlord/visit-requests');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($reservation->id);
});

test('BL-08 slot rules support grouped visits capacity and minimum notice hours', function (): void {
    Passport::actingAs($this->owner);

    $response = $this->putJson("/api/landlord/properties/{$this->listing->id}/slots", [
        'grouped' => true,
        'group_size' => 6,
        'minimum_hours' => 12,
        'days' => [
            ['day' => now()->addDays(2)->dayOfWeek, 'from' => '10:00', 'to' => '12:00'],
        ],
        'horizon_days' => 5,
    ]);

    $response->assertOk();
    $slots = $response->json('data.slots');
    expect($slots)->toBeArray()->and(count($slots))->toBeGreaterThan(0);
    expect($slots[0]['capacity'])->toBe(6);

    // Vérifier minimum_hours sauvegardé sur l'annonce
    expect($this->listing->fresh()->attributes['minimum_hours'])->toBe(12);
});
