<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\Boost\CreditWallet;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('point 45: in-app store verify endpoint activates subscription and badge', function () {
    $user = User::factory()->create(['is_verified' => true]);

    $product = SubscriptionProduct::create([
        'code' => 'badge_landlord',
        'name' => 'Badge Bailleur',
        'badge_type' => 'landlord',
        'ring_color' => '#1E9E5A',
        'is_active' => true,
    ]);

    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'cadence' => 'monthly',
        'duration_days' => 30,
        'price_amount' => 5000,
        'currency' => 'XAF',
        'is_active' => true,
        'effective_from' => now()->subDay(),
    ]);

    $res = $this->actingAs($user, 'api')->postJson('/api/payments/store/verify', [
        'store' => 'apple',
        'product_id' => 'badge_landlord',
        'receipt' => 'fake-apple-receipt-data-xyz',
        'price_id' => $price->id,
        'transaction_id' => 'APPLE-TX-12345',
    ]);

    $res->assertOk();
    expect($res->json('data.status'))->toBe(PaymentStatus::Confirmed->value);
    expect($res->json('data.provider'))->toBe(PaymentProvider::Apple->value);

    // Verify subscription created and user badge updated
    $user->refresh();
    expect($user->badge)->toBe('landlord');
    expect($user->subscriptions()->where('state', 'active')->count())->toBe(1);
});

test('point 45: credit wallet payment on payments initiate end to end', function () {
    $user = User::factory()->create(['is_verified' => true]);

    $product = SubscriptionProduct::create([
        'code' => 'badge_agent',
        'name' => 'Badge Agent',
        'badge_type' => 'agent',
        'ring_color' => '#1E9E5A',
        'is_active' => true,
    ]);

    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'cadence' => 'monthly',
        'duration_days' => 30,
        'price_amount' => 5000,
        'currency' => 'XAF',
        'is_active' => true,
        'effective_from' => now()->subDay(),
    ]);

    // 1. Insufficient funds
    $wallet = CreditWallet::create([
        'user_id' => $user->id,
        'balance' => 1000, // Insufficient for 5000
        'currency' => 'XAF',
    ]);

    $resFail = $this->actingAs($user, 'api')->postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'channel' => 'credit_wallet',
        'idempotency_key' => 'tx-credit-insufficient-1',
    ]);
    $resFail->assertStatus(422);
    expect($resFail->json('error.code'))->toBe('INSUFFICIENT_CREDIT');

    // 2. Sufficient funds
    $wallet->update(['balance' => 10000]);

    $resSuccess = $this->actingAs($user, 'api')->postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'channel' => 'credit_wallet',
        'idempotency_key' => 'tx-credit-success-1',
    ]);

    $resSuccess->assertOk();
    expect($resSuccess->json('data.status'))->toBe(PaymentStatus::Confirmed->value);
    expect($resSuccess->json('data.channel'))->toBe(PaymentChannel::CreditWallet->value);

    // Wallet debited
    $wallet->refresh();
    expect($wallet->balance)->toBe(10000 - (int) $resSuccess->json('data.total_amount'));

    // User badge active
    $user->refresh();
    expect($user->badge)->toBe('agent');
    expect($user->subscriptions()->where('state', 'active')->count())->toBe(1);
});
