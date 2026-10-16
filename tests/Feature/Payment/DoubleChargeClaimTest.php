<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('rejects unauthenticated requests on double charge claim endpoints', function () {
    postJson('/api/payments/double-charge-claims', [])->assertStatus(401);
    getJson('/api/payments/double-charge-claims/mine')->assertStatus(401);
});

it('submits a valid double charge claim linked to a transaction without triggering any refund or balance change', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-CONFIRMED-999',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
    ]);

    $payload = [
        'transaction_id' => $transaction->id,
        'operator' => 'mtn',
        'operator_reference' => 'MTN-REF-123456789',
        'disputed_amount' => 5100,
        'claimant_phone' => '+237 671 23 45 67',
        'reason' => 'J\'ai été débité deux fois pour cette même visite immobilière.',
    ];

    $response = postJson('/api/payments/double-charge-claims', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.operator_reference', 'MTN-REF-123456789')
        ->assertJsonPath('data.disputed_amount', 5100)
        ->assertJsonPath('data.status', 'pending');

    assertDatabaseHas('payment_disputes', [
        'user_id' => $user->id,
        'transaction_id' => $transaction->id,
        'type' => 'double_charge',
        'operator_reference' => 'MTN-REF-123456789',
        'status' => 'pending',
    ]);

    // Critical: Transaction status remains Confirmed, no automatic refund or wallet change
    expect($transaction->fresh()->status)->toBe(PaymentStatus::Confirmed);
    expect($transaction->fresh()->refunded_at)->toBeNull();
});

it('validates required fields on double charge claim submission', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    postJson('/api/payments/double-charge-claims', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['operator', 'operator_reference', 'disputed_amount', 'claimant_phone', 'reason']);
});

it('forbids claiming a double charge on another user transaction', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $transaction = PaymentTransaction::create([
        'reference' => 'TX-USER1-AAA',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user1->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 5000,
        'fee_amount' => 100,
        'tax_amount' => 0,
        'total_amount' => 5100,
        'amount_charged' => 5100,
        'status' => PaymentStatus::Confirmed,
    ]);

    Passport::actingAs($user2);

    postJson('/api/payments/double-charge-claims', [
        'transaction_id' => $transaction->id,
        'operator' => 'mtn',
        'operator_reference' => 'MTN-REF-999999',
        'disputed_amount' => 5100,
        'claimant_phone' => '+237 671 00 00 00',
        'reason' => 'Tentative de réclamation frauduleuse.',
    ])->assertStatus(403);
});

it('rejects duplicate claims with the same operator reference', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    PaymentDispute::create([
        'user_id' => $user->id,
        'type' => 'double_charge',
        'status' => 'pending',
        'operator_reference' => 'DUPLICATE-OP-REF-888',
        'claimant_phone' => '+237671234567',
        'disputed_amount' => 5000,
        'currency' => 'XAF',
        'reason' => 'Premier signalement.',
    ]);

    $response = postJson('/api/payments/double-charge-claims', [
        'operator' => 'mtn',
        'operator_reference' => 'DUPLICATE-OP-REF-888',
        'disputed_amount' => 5000,
        'claimant_phone' => '+237 671 23 45 67',
        'reason' => 'Deuxième signalement identique.',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('status', 'pending');

    assertDatabaseCount('payment_disputes', 1);
});

it('lists the authenticated user claims only', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    PaymentDispute::create([
        'user_id' => $user1->id,
        'type' => 'double_charge',
        'status' => 'pending',
        'operator_reference' => 'REF-USER1',
        'claimant_phone' => '+237671111111',
        'disputed_amount' => 5000,
        'currency' => 'XAF',
        'reason' => 'Réclamation user 1.',
    ]);

    PaymentDispute::create([
        'user_id' => $user2->id,
        'type' => 'double_charge',
        'status' => 'pending',
        'operator_reference' => 'REF-USER2',
        'claimant_phone' => '+237699222222',
        'disputed_amount' => 7000,
        'currency' => 'XAF',
        'reason' => 'Réclamation user 2.',
    ]);

    Passport::actingAs($user1);

    $response = getJson('/api/payments/double-charge-claims/mine');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.operator_reference', 'REF-USER1');
});
