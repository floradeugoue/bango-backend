<?php

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Boost\CreditWallet;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\ReceiptPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('paying badge subscription with credit wallet has provider=wallet, 0 fee, and debits base price only', function () {
    $user = User::factory()->create();

    $product = SubscriptionProduct::create([
        'code' => 'landlord_pro',
        'name' => 'Badge Bailleur Vérifié',
        'badge_type' => 'landlord',
        'is_active' => true,
    ]);

    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'cadence' => 'monthly',
        'price_amount' => 7500,
        'currency' => 'XAF',
        'duration_days' => 30,
        'is_active' => true,
        'effective_from' => now()->subDay(),
    ]);

    // 1. Quoting with gateway=wallet or channel=credit_wallet
    $quoteRes = $this->actingAs($user, 'api')->postJson('/api/payments/quote', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'channel' => 'credit_wallet',
    ]);

    $quoteRes->assertOk();
    expect((float) $quoteRes->json('data.base_amount'))->toBe(7500.0)
        ->and((float) $quoteRes->json('data.fee_amount'))->toBe(0.0)
        ->and((float) $quoteRes->json('data.total_amount'))->toBe(7500.0);

    // 2. Setup user credit wallet with 10 000 credits
    $wallet = CreditWallet::create([
        'user_id' => $user->id,
        'balance' => 10000,
        'currency' => 'XAF',
    ]);

    // 3. Initiate payment via credit_wallet
    $initiateRes = $this->actingAs($user, 'api')->postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'channel' => 'credit_wallet',
        'idempotency_key' => 'tx-wallet-badge-test-1',
    ]);

    $initiateRes->assertOk();
    expect($initiateRes->json('data.status'))->toBe(PaymentStatus::Confirmed->value)
        ->and($initiateRes->json('data.channel'))->toBe(PaymentChannel::CreditWallet->value)
        ->and($initiateRes->json('data.provider'))->toBe('wallet')
        ->and((float) $initiateRes->json('data.amount'))->toBe(7500.0)
        ->and((float) $initiateRes->json('data.fee'))->toBe(0.0)
        ->and((float) $initiateRes->json('data.total_amount'))->toBe(7500.0)
        ->and((float) $initiateRes->json('data.creditUsed'))->toBe(7500.0);

    // Wallet balance must be debited exactly 7500 (10000 - 7500 = 2500)
    $wallet->refresh();
    expect((int) $wallet->balance)->toBe(2500);

    // Database verification
    $tx = PaymentTransaction::where('reference', $initiateRes->json('data.reference'))->firstOrFail();
    expect($tx->provider)->toBe(PaymentProvider::Wallet)
        ->and($tx->channel)->toBe(PaymentChannel::CreditWallet)
        ->and((float) $tx->amount)->toBe(7500.0)
        ->and((float) $tx->fee_amount)->toBe(0.0)
        ->and((float) $tx->total_amount)->toBe(7500.0)
        ->and((float) $tx->credit_used)->toBe(7500.0);

    // Receipt verification
    $pdfService = app(ReceiptPdfService::class);
    $pdf = $pdfService->generateReceiptPdf($tx);
    expect($pdf)->toContain('Portefeuille')
        ->and($pdf)->toContain('Reference debit')
        ->and($pdf)->not->toContain('Reference operateur');
});

test('refund command and migration reimburse excess 150 credits on past wallet payments and update provider to wallet', function () {
    $jeanPaul = User::factory()->create([
        'name' => 'Jean-Paul',
        'email' => 'jeanpaul.test@bango.app',
    ]);

    $wallet = CreditWallet::create([
        'user_id' => $jeanPaul->id,
        'balance' => 0,
        'currency' => 'XAF',
    ]);

    // Simulate the past faulty transaction: debited 7650 for a 7500 badge, provider=monetbil, fee=150
    $tx = PaymentTransaction::create([
        'reference' => 'BNG-TX-20261020-A2N9VQNZ',
        'user_id' => $jeanPaul->id,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::CreditWallet,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'fee_amount' => '150.00',
        'tax_amount' => '0.00',
        'total_amount' => '7650.00',
        'credit_used' => '7650.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Confirmed,
        'idempotency_key' => 'idem-jean-paul-badge-past',
        'external_reference' => 'WALLET-JP-TEST1234',
    ]);

    $tx->createAttempt(
        provider: PaymentProvider::Credit,
        channel: PaymentChannel::CreditWallet,
        externalReference: 'WALLET-JP-TEST1234',
        metadata: ['debited_amount' => 7650]
    );

    // Run the refund command
    $this->artisan('payment:refund-wallet-fees')
        ->assertExitCode(0);

    // 150 credits reimbursed to Jean-Paul
    $wallet->refresh();
    expect((int) $wallet->balance)->toBe(150);

    // Check credit transaction adjustment
    $adjTx = $jeanPaul->creditTransactions()
        ->where('type', CreditTransactionType::Adjustment)
        ->first();
    expect($adjTx)->not->toBeNull()
        ->and((int) $adjTx->amount)->toBe(150)
        ->and($adjTx->description)->toContain('BNG-TX-20261020-A2N9VQNZ');

    // Transaction updated
    $tx->refresh();
    expect($tx->provider)->toBe(PaymentProvider::Wallet)
        ->and((float) $tx->fee_amount)->toBe(0.0)
        ->and((float) $tx->total_amount)->toBe(7500.0)
        ->and((float) $tx->credit_used)->toBe(7500.0)
        ->and((float) $tx->amount_charged)->toBe(7500.0);

    // Running again is idempotent (does not double refund)
    $this->artisan('payment:refund-wallet-fees')
        ->assertExitCode(0);

    $wallet->refresh();
    expect((int) $wallet->balance)->toBe(150);
});
