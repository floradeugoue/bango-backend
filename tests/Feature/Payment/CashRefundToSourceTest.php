<?php

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Services\Boost\CreditWalletService;
use App\Services\Payment\Gateways\MonetbilGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

afterEach(fn () => MonetbilGateway::resetFakes());

it('refunds purchased credit balance in cash to the original payment method and ignores client phone input', function () {
    $disbursed = [];
    MonetbilGateway::fakeDisburse(function ($phone, $amount, $currency, $reference, $operator) use (&$disbursed) {
        $disbursed = compact('phone', 'amount', 'operator');

        return [
            'success' => true,
            'external_reference' => 'EXT-1',
            'status' => 'completed',
            'phone' => $phone,
            'operator' => $operator,
            'amount' => $amount,
            'currency' => $currency,
        ];
    });

    $user = User::factory()->create(['phone' => '+237699000000']);
    $walletService = app(CreditWalletService::class);

    // Transaction d'achat de crédits confirmée avec le vrai numéro payeur
    $tx = PaymentTransaction::create([
        'reference' => 'TX-CREDIT-PURCHASE-1',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 3000,
        'fee_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 3000,
        'amount_charged' => 3000,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
        'metadata' => [
            'phone' => '237699112233',
            'operator' => 'mtn',
        ],
    ]);

    $walletService->creditFromPaymentTransaction($tx);

    Passport::actingAs($user);

    // Le client tente d'injecter un autre numéro frauduleux
    $response = $this->postJson('/api/credits/wallet/refund', [
        'phone' => '237677889900',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.amount', 3000)
        ->assertJsonPath('data.refund_channel', 'original_payment_method')
        ->assertJsonPath('data.phone', '237699112233');

    // Le numéro déboursé DOIT être le numéro d'origine (237699112233) et non le numéro frauduleux
    expect($disbursed['phone'])->toBe('237699112233')
        ->and($disbursed['amount'])->toBe(3000.0)
        ->and($walletService->getBalance($user))->toBe(0.0);
});

it('rejects a cash refund when credits are only promotional bonuses', function () {
    $user = User::factory()->create();
    $walletService = app(CreditWalletService::class);
    $walletService->credit(
        account: $user,
        amount: 5000,
        type: CreditTransactionType::Bonus,
        description: 'Bonus promotionnel de bienvenue'
    );

    Passport::actingAs($user);

    $response = $this->postJson('/api/credits/wallet/refund');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['balance']);
});

it('rejects a cash refund when purchased credits have already been consumed', function () {
    $user = User::factory()->create();
    $walletService = app(CreditWalletService::class);

    $tx = PaymentTransaction::create([
        'reference' => 'TX-CREDIT-PURCHASE-2',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 2000,
        'total_amount' => 2000,
        'amount_charged' => 2000,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
        'metadata' => [
            'phone' => '237699112233',
            'operator' => 'mtn',
        ],
    ]);
    $walletService->creditFromPaymentTransaction($tx);

    // Ajout d'un bonus
    $walletService->credit($user, 3000, CreditTransactionType::Bonus);

    // Consommation de 2000 crédits (consomme la totalité du crédit acheté)
    $walletService->debit($user, 2000, source: 'boost');

    // Il reste 3000 crédits de bonus
    expect($walletService->getBalance($user))->toBe(3000.0);

    Passport::actingAs($user);

    $response = $this->postJson('/api/credits/wallet/refund');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['balance']);
});

it('rejects a cash refund when no original payment phone is found', function () {
    $user = User::factory()->create();
    $walletService = app(CreditWalletService::class);

    $tx = PaymentTransaction::create([
        'reference' => 'TX-CREDIT-PURCHASE-3',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => 2000,
        'total_amount' => 2000,
        'amount_charged' => 2000,
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now(),
        'metadata' => [],
    ]);
    $walletService->creditFromPaymentTransaction($tx);

    Passport::actingAs($user);

    $response = $this->postJson('/api/credits/wallet/refund');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['phone']);
});

it('rejects a cash refund when the credit balance is empty', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/credits/wallet/refund')->assertStatus(422);
});
