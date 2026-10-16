<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\Gateways\MonetbilGateway;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    MonetbilGateway::resetFakes();

    config([
        'payment.default_gateway' => 'monetbil',
        'payment.gateways.monetbil.enabled' => true,
        'payment.gateways.monetbil.service_key' => 'mock_service_key_123',
        'payment.gateways.monetbil.service_secret' => 'mock_service_secret_456',
        'payment.gateways.monetbil.api_base_url' => 'https://api.monetbil.com',
    ]);
});

function calculateMonetbilSignature(array $params, string $secret = 'mock_service_secret_456'): string
{
    unset($params['sign']);
    ksort($params);

    return md5($secret.implode('', $params));
}

it('rejects webhooks with invalid or missing signature with 403 Forbidden', function () {
    $params = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_fake',
        'status' => 'success',
        'amount' => '7650',
    ];

    // Signature invalide
    postJson('/api/webhooks/payments/monetbil', array_merge($params, ['sign' => 'tampered_sign']))
        ->assertStatus(403)
        ->assertJson(['status' => 'error', 'message' => 'Signature invalide.']);

    // Signature absente
    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(403);
});

it('returns 404 if webhook references an unknown transaction', function () {
    $params = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_unknown_999',
        'item_ref' => 'BNG-TX-UNKNOWN',
        'status' => 'success',
        'amount' => '7650',
        'currency' => 'XAF',
    ];

    $sign = calculateMonetbilSignature($params);
    $params['sign'] = $sign;

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(404)
        ->assertJson(['status' => 'error', 'message' => 'Transaction introuvable.']);
});

it('detects amount or currency tampering and logs fraud alert', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'currency' => 'XAF',
    ]);

    // 1. Devise différente
    $tamperedCurrency = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_tamper_1',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '7650',
        'currency' => 'USD',
    ];
    $tamperedCurrency['sign'] = calculateMonetbilSignature($tamperedCurrency);

    postJson('/api/webhooks/payments/monetbil', $tamperedCurrency)
        ->assertStatus(422)
        ->assertJson(['status' => 'error', 'message' => 'Devise incohérente.']);

    expect($tx->events()->where('event_type', 'fraud_alert')->count())->toBe(1);

    // 2. Montant différent
    $tamperedAmount = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_tamper_2',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '100', // Moins que le total
        'currency' => 'XAF',
    ];
    $tamperedAmount['sign'] = calculateMonetbilSignature($tamperedAmount);

    postJson('/api/webhooks/payments/monetbil', $tamperedAmount)
        ->assertStatus(422)
        ->assertJson(['status' => 'error', 'message' => 'Montant incohérent.']);

    expect($tx->events()->where('event_type', 'fraud_alert')->count())->toBe(2);
});

it('processes verified success webhook and activates SUB subscription atomically', function () {
    $user = User::factory()->create(['is_verified' => true]);
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'credit_used' => '0.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Processing,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    // Mock checkPayment retournant confirmation officielle
    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'MP_WEBHOOK_778899',
                'operator' => 'CM_ORANGEMONEY',
            ],
        ], 200),
    ]);

    $params = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_webhook_valid',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'XAF',
        'operator' => 'CM_ORANGEMONEY',
        'operator_transaction_id' => 'MP_WEBHOOK_778899',
    ];
    $params['sign'] = calculateMonetbilSignature($params);

    $res = postJson('/api/webhooks/payments/monetbil', $params);

    $res->assertStatus(200)
        ->assertJson(['status' => 'success']);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed)
        ->and($tx->subscription_id)->not->toBeNull();

    $sub = Subscription::find($tx->subscription_id);
    expect($sub->state)->toBe('active')
        ->and($sub->user_id)->toBe($user->id);

    $user->refresh();
    expect($user->badge)->toBe('landlord');

    // Vérifier l'événement de raccordement
    expect($tx->events()->where('event_type', 'subscription_fulfilled')->count())->toBe(1);
});

it('is strictly idempotent on replayed webhooks and prevents duplicate subscription activations', function () {
    $user = User::factory()->create(['is_verified' => true]);
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'status' => PaymentStatus::Processing,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => ['status' => 1],
        ], 200),
    ]);

    $params = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_replay_webhook',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'XAF',
    ];
    $params['sign'] = calculateMonetbilSignature($params);

    // 1ère notification
    postJson('/api/webhooks/payments/monetbil', $params)->assertStatus(200);
    $initialSubId = $tx->refresh()->subscription_id;
    $initialSubCount = Subscription::where('user_id', $user->id)->count();

    // 2ème notification rejouée (réémission réseau Monetbil)
    postJson('/api/webhooks/payments/monetbil', $params)->assertStatus(200);

    expect($tx->refresh()->subscription_id)->toBe($initialSubId)
        ->and(Subscription::where('user_id', $user->id)->count())->toBe($initialSubCount);
});

it('credits user CreditWallet after Monetbil confirms a credit purchase webhook', function () {
    $user = User::factory()->create();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Credit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '2500.00',
        'total_amount' => '2500.00',
        'credit_used' => '0.00',
        'amount_charged' => '2500.00',
        'status' => PaymentStatus::Processing,
        'idempotency_key' => 'idemp_credit_wb_'.Str::uuid(),
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'MP_CRED_9988',
                'operator' => 'CM_ORANGEMONEY',
            ],
        ], 200),
    ]);

    $params = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_cred_valid',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '2500.00',
        'currency' => 'XAF',
        'operator' => 'CM_ORANGEMONEY',
        'operator_transaction_id' => 'MP_CRED_9988',
    ];
    $params['sign'] = calculateMonetbilSignature($params);

    $res = postJson('/api/webhooks/payments/monetbil', $params);

    $res->assertStatus(200)
        ->assertJson(['status' => 'success']);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed);

    $wallet = $user->fresh()->creditWallet;
    expect($wallet)->not->toBeNull()
        ->and((int) $wallet->balance)->toBe(2500);

    // Rejeu du webhook : ne crédite pas une deuxième fois
    postJson('/api/webhooks/payments/monetbil', $params)->assertStatus(200);
    expect((int) $wallet->fresh()->balance)->toBe(2500);
});
