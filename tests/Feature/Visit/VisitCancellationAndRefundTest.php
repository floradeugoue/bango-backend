<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
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

    $this->agent = User::factory()->create([
        'badge' => 'agent',
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
        'agent_id' => $this->agent->id,
        'title' => 'Penthouse Bonapriso',
        'visit_fee' => 5000,
        'currency' => 'XAF',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(3)->setTime(14, 0),
        'end_at' => now()->addDays(3)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $this->reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);

    $this->transaction = PaymentTransaction::create([
        'user_id' => $this->prospect->id,
        'service_type' => PaymentServiceType::Visit,
        'payable_type' => VisitReservation::class,
        'payable_id' => $this->reservation->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'total_amount' => 5000,
        'amount_charged' => 5000,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'CANCEL-TEST-'.uniqid(),
    ]);

    $this->reservation->transaction_id = $this->transaction->id;
    $this->reservation->payment_reference = $this->transaction->reference;
    $this->reservation->save();
    $this->slot->increment('booked_count');
});

test('prospect can list their personal visits and cannot view other prospects visits', function (): void {
    // Autre visite appartenant à un tiers
    VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->otherProspect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XAF',
    ]);

    Passport::actingAs($this->prospect);

    $response = $this->getJson('/api/visits/mine');
    $response->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['id'])->toBe($this->reservation->id);

    // Tentative d'accès individuel à la visite d'un tiers
    $otherReservation = VisitReservation::where('prospect_id', $this->otherProspect->id)->first();
    $showResponse = $this->getJson("/api/visits/{$otherReservation->id}");
    $showResponse->assertForbidden();
});

test('prospect cancellation more than 24h before visit triggers full refund (IM-14)', function (): void {
    Passport::actingAs($this->prospect);

    // Le créneau est dans 3 jours (> 24h)
    $response = $this->postJson("/api/visits/{$this->reservation->id}/cancel", [
        'reason' => 'Imprévu professionnel',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Refunded->value)
        ->and((float) $data['refund_amount'])->toEqual(5000.0)
        ->and($data['refund_channel'])->toBe('bango_credit')
        ->and($data['cancellation_reason'])->toBe('Imprévu professionnel');

    // La capacité du créneau doit être libérée
    expect($this->slot->fresh()->booked_count)->toBe(0)
        ->and($this->transaction->fresh()->status)->toBe(PaymentStatus::Refunded);
});

test('prospect cancellation less than 24h before visit retains fees without refund (IM-14)', function (): void {
    Passport::actingAs($this->prospect);

    // Déplacer le créneau à dans 5 heures (< 24h)
    $this->slot->start_at = now()->addHours(5);
    $this->slot->end_at = now()->addHours(5)->addMinutes(30);
    $this->slot->save();

    $response = $this->postJson("/api/visits/{$this->reservation->id}/cancel", [
        'reason' => 'Empêchement de dernière minute',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Cancelled->value)
        ->and($data['refund_amount'])->toBeNull();

    // La capacité est libérée mais la transaction n'est pas remboursée
    expect($this->slot->fresh()->booked_count)->toBe(0)
        ->and($this->transaction->fresh()->status)->toBe(PaymentStatus::Confirmed);
});

test('landlord or agent cancellation always triggers full refund regardless of delay', function (): void {
    Passport::actingAs($this->owner);

    // Déplacer le créneau à dans 2 heures
    $this->slot->start_at = now()->addHours(2);
    $this->slot->end_at = now()->addHours(2)->addMinutes(30);
    $this->slot->save();

    $response = $this->postJson("/api/visits/{$this->reservation->id}/cancel", [
        'reason' => 'Logement indisponible pour travaux urgents',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Refunded->value)
        ->and((float) $data['refund_amount'])->toEqual(5000.0)
        ->and($data['refund_channel'])->toBe('bango_credit');

    expect($this->slot->fresh()->booked_count)->toBe(0);
});

test('landlord can decline a visit request and trigger full refund', function (): void {
    Passport::actingAs($this->owner);

    $response = $this->postJson("/api/landlord/visits/{$this->reservation->id}/answer", [
        'action' => 'decline',
        'reason' => 'Bailleur indisponible',
    ]);

    $response->assertOk();
    $data = $response->json('data');

    expect($data['status'])->toBe(VisitReservationStatus::Declined->value)
        ->and($data['decline_reason'])->toBe('Bailleur indisponible')
        ->and((float) $data['refund_amount'])->toEqual(5000.0);

    expect($this->slot->fresh()->booked_count)->toBe(0);
});

test('landlord can propose an alternative slot and prospect can accept it', function (): void {
    Passport::actingAs($this->owner);

    $altSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(4)->setTime(16, 0),
        'end_at' => now()->addDays(4)->setTime(16, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    // 1. Le bailleur propose le nouveau créneau
    $proposeResponse = $this->postJson("/api/landlord/visits/{$this->reservation->id}/answer", [
        'action' => 'propose',
        'proposal_slot_id' => $altSlot->id,
    ]);

    $proposeResponse->assertOk();
    expect($proposeResponse->json('data.status'))->toBe(VisitReservationStatus::Proposed->value)
        ->and($proposeResponse->json('data.proposal_slot.id'))->toBe($altSlot->id);

    // 2. Le prospect accepte la proposition
    Passport::actingAs($this->prospect);
    $acceptResponse = $this->postJson("/api/visits/{$this->reservation->id}/proposal/respond", [
        'accept' => true,
    ]);

    $acceptResponse->assertOk();
    expect($acceptResponse->json('data.status'))->toBe(VisitReservationStatus::Confirmed->value)
        ->and($acceptResponse->json('data.slot_id'))->toBe($altSlot->id);

    expect($altSlot->fresh()->booked_count)->toBe(1);
});

test('prospect rejecting a proposal cancels visit with full refund', function (): void {
    Passport::actingAs($this->owner);

    $altSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(4)->setTime(16, 0),
        'end_at' => now()->addDays(4)->setTime(16, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $this->postJson("/api/landlord/visits/{$this->reservation->id}/answer", [
        'action' => 'propose',
        'proposal_slot_id' => $altSlot->id,
    ]);

    Passport::actingAs($this->prospect);
    $rejectResponse = $this->postJson("/api/visits/{$this->reservation->id}/proposal/respond", [
        'accept' => false,
    ]);

    $rejectResponse->assertOk();
    expect($rejectResponse->json('data.status'))->toBe(VisitReservationStatus::Refunded->value)
        ->and((float) $rejectResponse->json('data.refund_amount'))->toEqual(5000.0);
});
