<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Geo\Country;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Gateways\MonetbilGateway;
use Database\Seeders\GeoSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
    $this->seed(GeoSeeder::class);

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

it('correctly normalizes phone numbers for different African countries', function () {
    $gateway = new MonetbilGateway;

    // Cameroun
    expect($gateway->normalizePhoneNumber('+237699112233', 'CM'))->toBe('237699112233')
        ->and($gateway->normalizePhoneNumber('677112233', 'CM'))->toBe('237677112233')
        ->and($gateway->normalizePhoneNumber('00237655443322', 'CM'))->toBe('237655443322');

    // Sénégal
    expect($gateway->normalizePhoneNumber('+221771234567', 'SN'))->toBe('221771234567')
        ->and($gateway->normalizePhoneNumber('771234567', 'SN'))->toBe('221771234567')
        ->and($gateway->normalizePhoneNumber('00221771234567', 'SN'))->toBe('221771234567');

    // Côte d'Ivoire
    expect($gateway->normalizePhoneNumber('+2250701020304', 'CI'))->toBe('2250701020304')
        ->and($gateway->normalizePhoneNumber('0701020304', 'CI'))->toBe('2250701020304');

    // Gabon
    expect($gateway->normalizePhoneNumber('+241074112233', 'GA'))->toBe('241074112233')
        ->and($gateway->normalizePhoneNumber('074112233', 'GA'))->toBe('241074112233');

    // Auto-détection par indicatif
    expect($gateway->normalizePhoneNumber('+221770001122'))->toBe('221770001122')
        ->and($gateway->normalizePhoneNumber('+237699001122'))->toBe('237699001122');
});

it('passes Senegal country and XOF currency to Monetbil when paying in Senegal', function () {
    $senegal = Country::where('code', 'SN')->first();
    $user = User::factory()->create([
        'country_code' => 'SN',
        'country_id' => $senegal?->id,
    ]);

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XOF',
        'amount' => '5000.00',
        'fee_amount' => '100.00',
        'tax_amount' => '0.00',
        'total_amount' => '5100.00',
        'credit_used' => '0.00',
        'amount_charged' => '5100.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_pay_sn_12345',
            'message' => 'Demande USSD envoyée',
            'channel_ussd' => '#144#',
            'operator' => 'SN_ORANGEMONEY',
        ], 200),
    ]);

    $gateway = new MonetbilGateway;
    $result = $gateway->initiatePayment($tx, [
        'phone' => '771234567',
        'operator' => 'orange',
    ]);

    expect($result->isSuccessful)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request['country'] === 'SN'
            && $request['currency'] === 'XOF'
            && $request['phonenumber'] === '221771234567'
            && $request['operator'] === 'SN_ORANGEMONEY';
    });
});

it('returns failure with COUNTRY_NOT_SUPPORTED when country is outside Monetbil coverage', function () {
    $nigeria = Country::where('code', 'NG')->first();
    $user = User::factory()->create([
        'country_code' => 'NG',
        'country_id' => $nigeria?->id,
    ]);

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'NGN',
        'amount' => '5000.00',
        'fee_amount' => '0.00',
        'tax_amount' => '0.00',
        'total_amount' => '5000.00',
        'credit_used' => '0.00',
        'amount_charged' => '5000.00',
        'status' => PaymentStatus::Pending,
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    $gateway = new MonetbilGateway;
    $result = $gateway->initiatePayment($tx, [
        'phone' => '+2348012345678', // Numéro nigérian
    ]);

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->errorCode)->toBe('COUNTRY_NOT_SUPPORTED');
});

it('returns HTTP 422 with COUNTRY_NOT_SUPPORTED via POST /api/payments/initiate for unsupported countries', function () {
    $user = User::factory()->create([
        'country_code' => 'FR',
    ]);

    $response = $this->actingAs($user, 'api')->postJson('/api/payments/initiate', [
        'kind' => 'visit',
        'amount' => 5000,
        'phone' => '+33612345678',
        'country' => 'FR',
        'key' => 'idemp_test_'.Str::uuid(),
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'COUNTRY_NOT_SUPPORTED',
            ],
        ]);
});

it('filters operators by country code in GET /api/operators', function () {
    $response = $this->getJson('/api/operators?country=SN');

    $response->assertStatus(200);
    $data = $response->json();

    expect(count($data))->toBeGreaterThan(0);
    foreach ($data as $op) {
        expect($op['country']['code'])->toBe('SN');
    }
});
