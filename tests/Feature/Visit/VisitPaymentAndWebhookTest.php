<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Payment\PaymentEngineService;
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

    $this->otherUser = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237677889900',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Duplex Akwa',
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

    $this->reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::PendingPayment,
        'lock_expires_at' => now()->addMinutes(15),
        'fee' => 5000,
        'currency' => 'XAF',
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
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'VISIT-TEST-'.uniqid(),
    ]);

    $this->reservation->transaction_id = $this->transaction->id;
    $this->reservation->payment_reference = $this->transaction->reference;
    $this->reservation->save();
});

test('client confirm endpoint requires authentication and verifies server proof', function (): void {
    // 1. Appel non authentifié
    $response = $this->postJson("/api/visits/{$this->reservation->id}/confirm");
    $response->assertUnauthorized();

    // 2. Appel par un tiers -> refus
    Passport::actingAs($this->otherUser);
    $response = $this->postJson("/api/visits/{$this->reservation->id}/confirm");
    $response->assertForbidden();

    // 3. Appel par le prospect alors que la transaction est toujours en attente sans preuve externe
    Passport::actingAs($this->prospect);
    $response = $this->postJson("/api/visits/{$this->reservation->id}/confirm");
    $response->assertOk();

    // Doit rester en pending_payment tant qu'aucune preuve serveur ou webhook n'a validé
    expect($this->reservation->fresh()->status)->toBe(VisitReservationStatus::PendingPayment);
});

test('webhook IPN confirmation transitions reservation to confirmed and increments slot booked_count', function (): void {
    $engine = app(PaymentEngineService::class);

    // Confirmation du paiement par le moteur (comme déclenché par le webhook IPN Monetbil)
    $confirmedTx = $engine->confirmAndFulfill(
        transaction: $this->transaction,
        externalReference: 'EXT-MONETBIL-12345',
        source: PaymentEventSource::Gateway,
        fulfillmentDetails: ['channel' => 'orange_money', 'status' => 'success']
    );

    expect($confirmedTx->status)->toBe(PaymentStatus::Confirmed);

    // Vérifier la réservation
    $freshReservation = $this->reservation->fresh();
    expect($freshReservation->status)->toBe(VisitReservationStatus::Confirmed)
        ->and($freshReservation->paid_at)->not->toBeNull()
        ->and($freshReservation->lock_expires_at)->toBeNull();

    // Vérifier l'incrément de capacité du créneau
    expect($this->slot->fresh()->booked_count)->toBe(1);
});

test('double webhook replay is strictly idempotent without double booking increment', function (): void {
    $engine = app(PaymentEngineService::class);

    // Premier webhook
    $engine->confirmAndFulfill(
        transaction: $this->transaction,
        externalReference: 'EXT-123',
        source: PaymentEventSource::Gateway
    );

    expect($this->slot->fresh()->booked_count)->toBe(1);

    // Second webhook (rejeu ou retry réseau)
    $engine->confirmAndFulfill(
        transaction: $this->transaction->fresh(),
        externalReference: 'EXT-123',
        source: PaymentEventSource::Gateway
    );

    // booked_count doit rester 1
    expect($this->slot->fresh()->booked_count)->toBe(1)
        ->and($this->reservation->fresh()->status)->toBe(VisitReservationStatus::Confirmed);
});

test('late payment after lock expiration triggers automatic compensation refund if slot is saturated', function (): void {
    // 1. Expirer artificiellement le verrou du premier prospect
    $this->reservation->lock_expires_at = now()->subMinutes(5);
    $this->reservation->save();

    // 2. Un autre prospect a réservé et confirmé le créneau entre-temps (saturant la capacité de 1)
    VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->otherUser->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);
    $this->slot->increment('booked_count');

    // 3. Le paiement tardif du premier prospect arrive après coup
    $engine = app(PaymentEngineService::class);
    $engine->confirmAndFulfill(
        transaction: $this->transaction,
        externalReference: 'EXT-LATE-999',
        source: PaymentEventSource::Gateway
    );

    // La réservation en conflit doit être mise en remboursement automatique
    $freshReservation = $this->reservation->fresh();
    expect($freshReservation->status)->toBe(VisitReservationStatus::Refunded)
        ->and((float) $freshReservation->refund_amount)->toEqual(5000.0)
        ->and($freshReservation->refund_channel)->toBe('bango_credit');

    // La capacité du créneau ne doit pas avoir dépassé 1
    expect($this->slot->fresh()->booked_count)->toBe(1);
});

test('strict PAY vs AB isolation: visit payment does not alter subscription data', function (): void {
    $initialSubscriptionsCount = Subscription::count();

    $engine = app(PaymentEngineService::class);
    $engine->confirmAndFulfill(
        transaction: $this->transaction,
        externalReference: 'EXT-MONETBIL-PAY-AB',
        source: PaymentEventSource::Gateway
    );

    // Aucune souscription d'abonnement ne doit avoir été créée ou modifiée
    expect(Subscription::count())->toBe($initialSubscriptionsCount)
        ->and($this->transaction->fresh()->subscription_id)->toBeNull();
});
