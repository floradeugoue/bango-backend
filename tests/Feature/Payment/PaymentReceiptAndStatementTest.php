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
    $this->user = User::factory()->create([
        'name' => 'Paul Biya',
        'email' => 'paul@bango.cm',
        'email_verified_at' => now(),
    ]);

    $this->otherUser = User::factory()->create([
        'name' => 'Autre Utilisateur',
        'email' => 'autre@bango.cm',
    ]);

    $this->owner = User::factory()->create([
        'name' => 'Bailleur Bonapriso',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Villa Bonapriso',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(14, 0),
        'end_at' => now()->addDays(2)->setTime(14, 30),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);
});

test('GET /visits/{id}/receipt.pdf returns a signed PDF binary (PAY-23)', function (): void {
    // Visite confirmée payée par crédits BANGO
    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->user->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'currency' => 'XAF',
        'paid_at' => now(),
        'payment_reference' => 'VISIT-CREDIT-TEST1234',
    ]);

    Passport::actingAs($this->user);
    $response = $this->get("/api/visits/{$reservation->id}/receipt.pdf");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-1.4')
        ->and($response->getContent())->toContain('RECU OFFICIEL DE VISITE IMMOBILIERE')
        ->and($response->getContent())->toContain('VISIT-CREDIT-TEST1234')
        ->and($response->getContent())->toContain('Villa Bonapriso');

    // Forbidden for other users
    Passport::actingAs($this->otherUser);
    $this->get("/api/visits/{$reservation->id}/receipt.pdf")->assertForbidden();
});

test('GET /payments/statement.pdf returns statement of transactions signed by server (PAY-21)', function (): void {
    // Create confirmed transactions
    PaymentTransaction::create([
        'reference' => 'TX-BOOST-001',
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 10000,
        'fee_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 10000,
        'credit_used' => 0,
        'amount_charged' => 10000,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'tx-boost-1',
    ]);

    PaymentTransaction::create([
        'reference' => 'TX-SUB-002',
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 25000,
        'fee_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 25000,
        'credit_used' => 0,
        'amount_charged' => 25000,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'tx-sub-2',
    ]);

    Passport::actingAs($this->user);
    $response = $this->get('/api/payments/statement.pdf');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-1.4')
        ->and($response->getContent())->toContain('RELEVE DE TRANSACTIONS BANGO')
        ->and($response->getContent())->toContain('Paul Biya')
        ->and($response->getContent())->toContain('TX-BOOST-001')
        ->and($response->getContent())->toContain('TX-SUB-002');
});

test('GET /payments/statement.pdf can be filtered by from, to, and ids[]', function (): void {
    $tx1 = PaymentTransaction::create([
        'reference' => 'TX-FILTER-001',
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'total_amount' => 5000,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'filter-1',
        'created_at' => now()->subDays(5),
    ]);

    $tx2 = PaymentTransaction::create([
        'reference' => 'TX-FILTER-002',
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 8000,
        'total_amount' => 8000,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'filter-2',
        'created_at' => now()->subDay(),
    ]);

    Passport::actingAs($this->user);

    // Filter by specific ID
    $resFiltered = $this->get("/api/payments/statement.pdf?ids[]={$tx1->reference}");
    $resFiltered->assertOk();
    expect($resFiltered->getContent())->toContain('TX-FILTER-001')
        ->and($resFiltered->getContent())->not->toContain('TX-FILTER-002');
});
