<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\InvalidPaymentTransitionException;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
});

it('creates a transaction with exact monetary amounts and auto-generated reference', function () {
    $user = User::factory()->create();

    $transaction = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'fee_amount' => '150.00',
        'tax_amount' => '0.00',
        'total_amount' => '7650.00',
        'credit_used' => '0.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    expect($transaction->exists)->toBeTrue()
        ->and($transaction->reference)->toStartWith('BNG-TX-')
        ->and($transaction->amount)->toBe('7500.00')
        ->and($transaction->fee_amount)->toBe('150.00')
        ->and($transaction->tax_amount)->toBe('0.00')
        ->and($transaction->total_amount)->toBe('7650.00')
        ->and($transaction->amount_charged)->toBe('7650.00')
        ->and($transaction->status)->toBe(PaymentStatus::Pending)
        ->and($transaction->service_type)->toBe(PaymentServiceType::Subscription)
        ->and($transaction->provider)->toBe(PaymentProvider::Monetbil)
        ->and($transaction->channel)->toBe(PaymentChannel::MobileMoney);

    // Initial audit event is automatically created
    expect($transaction->events)->toHaveCount(1);
    $firstEvent = $transaction->events->first();
    expect($firstEvent->event_type)->toBe('created')
        ->and($firstEvent->source)->toBe(PaymentEventSource::System)
        ->and($firstEvent->to_status)->toBe(PaymentStatus::Pending);
});

it('calculates total and amount charged accurately with static helpers', function () {
    // Total = amount + fee + tax
    $total = PaymentTransaction::calculateTotal('15000.00', '250.50', '50.25');
    expect($total)->toBe('15300.75');

    // Charged = max(0, total - credit_used)
    $charged = PaymentTransaction::calculateCharged('15300.75', '5000.00');
    expect($charged)->toBe('10300.75');

    // Partial coverage by credit where credit exceeds total
    $chargedZero = PaymentTransaction::calculateCharged('2000.00', '5000.00');
    expect($chargedZero)->toBe('0.00');
});

it('enforces uniqueness on transaction public reference', function () {
    $user = User::factory()->create();
    $ref = 'BNG-TX-20261014-UNIQUE01';

    PaymentTransaction::create([
        'reference' => $ref,
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Certification,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => '15000.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_key_1',
    ]);

    expect(fn () => PaymentTransaction::create([
        'reference' => $ref,
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Certification,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => '15000.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_key_2',
    ]))->toThrow(QueryException::class);
});

it('enforces uniqueness on idempotency key', function () {
    $user = User::factory()->create();
    $idempotencyKey = 'client_uuid_req_12345';

    PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => '2180.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => $idempotencyKey,
    ]);

    expect(fn () => PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => '2180.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => $idempotencyKey,
    ]))->toThrow(QueryException::class);
});

it('filters transactions through query scopes', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $txA1 = PaymentTransaction::factory()->create([
        'user_id' => $userA->id,
        'status' => PaymentStatus::Pending,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
    ]);

    $txA2 = PaymentTransaction::factory()->confirmed()->create([
        'user_id' => $userA->id,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil,
        'external_reference' => 'EXT_REF_A2',
    ]);

    $txB1 = PaymentTransaction::factory()->failed()->create([
        'user_id' => $userB->id,
        'service_type' => PaymentServiceType::Certification,
        'provider' => PaymentProvider::Apple,
    ]);

    expect(PaymentTransaction::forUser($userA)->count())->toBe(2)
        ->and(PaymentTransaction::forUser($userB)->count())->toBe(1)
        ->and(PaymentTransaction::pending()->count())->toBe(1)
        ->and(PaymentTransaction::confirmed()->count())->toBe(1)
        ->and(PaymentTransaction::failed()->count())->toBe(1)
        ->and(PaymentTransaction::service(PaymentServiceType::Subscription)->count())->toBe(1)
        ->and(PaymentTransaction::provider(PaymentProvider::Apple)->count())->toBe(1)
        ->and(PaymentTransaction::externalReference('EXT_REF_A2')->first()->id)->toBe($txA2->id);
});

it('controls status transitions and records audit events with timestamps', function () {
    $user = User::factory()->create();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => '7500.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    expect($tx->status)->toBe(PaymentStatus::Pending)
        ->and($tx->confirmed_at)->toBeNull();

    // 1. Pending -> Processing (USSD envoyé)
    $tx->transitionTo(PaymentStatus::Processing, PaymentEventSource::System, 'USSD push initiated');
    expect($tx->status)->toBe(PaymentStatus::Processing);

    // 2. Processing -> Confirmed (Notification webhook & vérification OK)
    $tx->transitionTo(PaymentStatus::Confirmed, PaymentEventSource::Gateway, 'Monetbil checkPayment confirmed', [
        'operator_code' => 'MTN-402',
    ]);
    expect($tx->status)->toBe(PaymentStatus::Confirmed)
        ->and($tx->confirmed_at)->not->toBeNull();

    // 3. Confirmed -> RefundPending
    $tx->transitionTo(PaymentStatus::RefundPending, PaymentEventSource::Admin, 'Support double charge ticket');
    expect($tx->status)->toBe(PaymentStatus::RefundPending);

    // 4. RefundPending -> Refunded
    $tx->transitionTo(PaymentStatus::Refunded, PaymentEventSource::Admin, 'Credited to BANGO wallet');
    expect($tx->status)->toBe(PaymentStatus::Refunded)
        ->and($tx->refunded_at)->not->toBeNull();

    // Check all events recorded
    $events = $tx->events()->get();
    expect($events)->toHaveCount(5); // created + 4 transitions
    expect($events->pluck('event_type')->all())->toBe([
        'created',
        'status_transition',
        'status_transition',
        'status_transition',
        'status_transition',
    ]);
});

it('rejects invalid status transitions with an exception', function () {
    $user = User::factory()->create();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => '7500.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    // Pending directly to Refunded is not allowed
    expect(fn () => $tx->transitionTo(PaymentStatus::Refunded))
        ->toThrow(InvalidPaymentTransitionException::class);

    // Mark as failed
    $tx->transitionTo(PaymentStatus::Failed, PaymentEventSource::Gateway, 'Insufficient funds');

    // Terminal status: Failed cannot transition to Confirmed
    expect(fn () => $tx->transitionTo(PaymentStatus::Confirmed))
        ->toThrow(InvalidPaymentTransitionException::class);
});
