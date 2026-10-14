<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Gateways\MonetbilGateway;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        'payment.timeout_seconds' => 120,
    ]);
});

it('normalizes Cameroonian phone numbers correctly', function () {
    $gateway = new MonetbilGateway;

    expect($gateway->normalizePhoneNumber('+237699112233'))->toBe('237699112233')
        ->and($gateway->normalizePhoneNumber('677112233'))->toBe('237677112233')
        ->and($gateway->normalizePhoneNumber('00237655443322'))->toBe('237655443322')
        ->and($gateway->normalizePhoneNumber('237 699 00 11 22'))->toBe('237699001122');
});

it('initiates payment successfully with simulated Monetbil API response', function () {
    $user = User::factory()->create();

    $tx = PaymentTransaction::create([
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

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_pay_99887766',
            'message' => 'Demande USSD envoyée',
            'channel_ussd' => '#150*50#',
            'operator' => 'CM_ORANGEMONEY',
        ], 200),
    ]);

    $gateway = new MonetbilGateway;
    $result = $gateway->initiatePayment($tx, [
        'phone' => '699001122',
        'operator' => 'CM_ORANGEMONEY',
    ]);

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->gatewayReference)->toBe('mb_pay_99887766')
        ->and($result->ussdPrompt)->toBe('#150*50#')
        ->and($result->timeoutSeconds)->toBe(120);

    // Vérification de la payload envoyée
    Http::assertSent(function ($request) use ($tx) {
        return $request->url() === 'https://api.monetbil.com/payment/v1/placePayment'
            && $request['service'] === 'mock_service_key_123'
            && $request['phonenumber'] === '237699001122'
            && (float) $request['amount'] === 7650.0
            && $request['item_ref'] === $tx->reference;
    });
});

it('handles gateway rejection gracefully', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create(['user_id' => $user->id]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'FAILED',
            'message' => 'Solde insuffisant chez l\'opérateur.',
        ], 200),
    ]);

    $gateway = new MonetbilGateway;
    $result = $gateway->initiatePayment($tx, ['phone' => '699001122']);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->errorCode)->toBe('FAILED')
        ->and($result->errorMessage)->toBe('Solde insuffisant chez l\'opérateur.');
});

it('handles connection timeout on initiate', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create(['user_id' => $user->id]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => function () {
            throw new ConnectionException('Connection timed out after 15000ms');
        },
    ]);

    $gateway = new MonetbilGateway;
    $timeoutResult = $gateway->initiatePayment($tx, ['phone' => '699001122']);

    expect($timeoutResult->isSuccessful)->toBeFalse()
        ->and($timeoutResult->errorCode)->toBe('GATEWAY_TIMEOUT');
});

it('handles malformed non-JSON gateway response', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create(['user_id' => $user->id]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response('<html>Error 502 Bad Gateway</html>', 502),
    ]);

    $gateway = new MonetbilGateway;
    $errorResult = $gateway->initiatePayment($tx, ['phone' => '699001122']);

    expect($errorResult->isSuccessful)->toBeFalse()
        ->and($errorResult->errorCode)->toBe('GATEWAY_HTTP_502');
});

it('verifies confirmed transaction status through checkPayment API', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'external_reference' => 'mb_pay_123456',
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'amount' => 7650,
                'operator_transaction_id' => 'MP260314.0942.C41773',
                'operator' => 'CM_ORANGEMONEY',
            ],
        ], 200),
    ]);

    $gateway = new MonetbilGateway;
    $confirmedResult = $gateway->checkStatus($tx);

    expect($confirmedResult->status)->toBe(PaymentStatus::Confirmed)
        ->and($confirmedResult->operatorReference)->toBe('MP260314.0942.C41773');
});

it('verifies pending transaction status through checkPayment API', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'external_reference' => 'mb_pay_pending',
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => -1,
            ],
        ], 200),
    ]);

    $gateway = new MonetbilGateway;
    $pendingResult = $gateway->checkStatus($tx);

    expect($pendingResult->status)->toBe(PaymentStatus::Processing);
});

it('verifies failed transaction status through checkPayment API', function () {
    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'external_reference' => 'mb_pay_failed',
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 0,
                'message' => 'Transaction annulée par l\'utilisateur',
            ],
        ], 200),
    ]);

    $gateway = new MonetbilGateway;
    $failedResult = $gateway->checkStatus($tx);

    expect($failedResult->status)->toBe(PaymentStatus::Failed);
});

it('validates timing-safe MD5 webhook signature', function () {
    $gateway = new MonetbilGateway;
    $secret = 'mock_service_secret_456';

    $params = [
        'service' => 'mock_service_key_123',
        'paymentId' => 'mb_pay_998877',
        'status' => 'success',
        'amount' => '7650',
        'currency' => 'XAF',
        'item_ref' => 'BNG-TX-20261014-TEST',
    ];

    // Calcul de la signature officielle
    $testParams = $params;
    ksort($testParams);
    $validSign = md5($secret.implode('', $testParams));

    // Avec signature valide
    expect($gateway->verifyWebhookSignature($params, $validSign))->toBeTrue();

    // Avec signature falsifiée
    expect($gateway->verifyWebhookSignature($params, 'invalid_signature_hash'))->toBeFalse();

    // Avec signature vide
    expect($gateway->verifyWebhookSignature($params, null))->toBeFalse();
});
