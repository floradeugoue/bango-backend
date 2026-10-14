<?php

use App\Enums\Payment\PaymentAttemptStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentAttempt;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
});

it('verifies Eloquent relationships between transactions, attempts, and events', function () {
    $user = User::factory()->create();
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => $price->price_amount,
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    // Test relation with User, Product, Price
    expect($tx->user->id)->toBe($user->id)
        ->and($tx->product->id)->toBe($product->id)
        ->and($tx->price->id)->toBe($price->id)
        ->and($user->paymentTransactions->first()->id)->toBe($tx->id);

    // Create 2 attempts
    $attempt1 = $tx->createAttempt(PaymentProvider::Monetbil, PaymentChannel::MobileMoney, 'REF_ATT_1');
    $attempt1->markFailed('USER_TIMEOUT', 'User did not enter PIN');

    $attempt2 = $tx->createAttempt(PaymentProvider::Monetbil, PaymentChannel::MobileMoney, 'REF_ATT_2');
    $attempt2->markSuccessful('FINAL_OPERATOR_REF');

    // Refresh transaction relations
    $tx->refresh();
    expect($tx->attempts)->toHaveCount(2)
        ->and($tx->latestAttempt->id)->toBe($attempt2->id)
        ->and($attempt1->transaction->id)->toBe($tx->id)
        ->and($attempt2->status)->toBe(PaymentAttemptStatus::Successful);

    // Events generated
    expect($tx->events()->count())->toBeGreaterThanOrEqual(4);
});

it('enforces attempt_number uniqueness per transaction', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create(['user_id' => $user->id]);

    PaymentAttempt::create([
        'transaction_id' => $tx->id,
        'attempt_number' => 1,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'status' => PaymentAttemptStatus::Initiated,
    ]);

    // Attempting same attempt_number for same transaction
    expect(fn () => PaymentAttempt::create([
        'transaction_id' => $tx->id,
        'attempt_number' => 1,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'status' => PaymentAttemptStatus::Initiated,
    ]))->toThrow(QueryException::class);
});

it('enforces append-only immutability on payment events', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create(['user_id' => $user->id]);

    $event = $tx->events()->first();
    expect($event)->not->toBeNull();

    // Trying to update throws BadMethodCallException
    expect(fn () => $event->update(['event_type' => 'tampered']))
        ->toThrow(BadMethodCallException::class);

    // Trying to delete throws BadMethodCallException
    expect(fn () => $event->delete())
        ->toThrow(BadMethodCallException::class);
});

it('protects financial history by restricting deletion of users with transactions', function () {
    $user = User::factory()->create();
    PaymentTransaction::factory()->create(['user_id' => $user->id]);

    // Hard deleting a user with financial transactions must be restricted by foreign key
    expect(fn () => $user->forceDelete())->toThrow(QueryException::class);
});

it('protects attempts and events from cascade deletion when transactions exist', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create(['user_id' => $user->id]);
    $tx->createAttempt(PaymentProvider::Monetbil);

    // Hard deleting a transaction that has attempts must be rejected by foreign key restrict
    expect(fn () => $tx->delete())->toThrow(QueryException::class);
});

it('isolates financial transactions between users', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    PaymentTransaction::factory()->count(3)->create(['user_id' => $alice->id]);
    PaymentTransaction::factory()->count(2)->create(['user_id' => $bob->id]);

    expect($alice->paymentTransactions()->count())->toBe(3)
        ->and($bob->paymentTransactions()->count())->toBe(2)
        ->and(PaymentTransaction::forUser($alice)->pluck('user_id')->unique()->all())->toBe([$alice->id])
        ->and(PaymentTransaction::forUser($bob)->pluck('user_id')->unique()->all())->toBe([$bob->id]);
});

it('confirms non-regression of Laravel Passport and SUB subscription linkage', function () {
    $user = User::factory()->create();

    // Passport token generation works seamlessly
    Passport::actingAs($user);
    expect(auth('api')->user()->id)->toBe($user->id);

    // Check relationship with Subscription model
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $subscription = Subscription::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'state' => 'active',
        'amount' => $price->price_amount,
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(30),
    ]);

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'subscription_id' => $subscription->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'amount' => $price->price_amount,
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    expect($subscription->paymentTransactions->first()->id)->toBe($tx->id)
        ->and($tx->subscription->id)->toBe($subscription->id);
});
