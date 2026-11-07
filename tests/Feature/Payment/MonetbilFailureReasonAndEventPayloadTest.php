<?php

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Http\Resources\Payment\PaymentTransactionResource;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Gateways\MonetbilGateway;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

it('preserves raw checkPayment response in status_transition event payload and exposes failure_reason in resource', function () {
    $user = User::factory()->create();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '5000.00',
        'fee_amount' => '100.00',
        'tax_amount' => '0.00',
        'total_amount' => '5100.00',
        'credit_used' => '0.00',
        'amount_charged' => '5100.00',
        'status' => PaymentStatus::Processing,
        'external_reference' => 'mb_pay_fail_test',
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    // Simuler la réponse checkPayment d'Orange / Monetbil avec solde insuffisant
    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'status' => 0,
            'message' => 'Transaction cancelled',
            'transaction' => [
                'status' => 0,
                'message' => 'Solde insuffisant',
                'error' => 'INSUFFICIENT_FUNDS',
                'operator' => 'CM_ORANGEMONEY',
            ],
        ], 200),
    ]);

    // Polling du statut via l'API GET /api/payments/{id}/status
    $response = $this->actingAs($user, 'api')->getJson("/api/payments/{$tx->id}/status");

    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['status'])->toBe(PaymentStatus::Failed->value)
        ->and($data['failure_reason'])->toBe('Solde insuffisant');

    // Vérifier l'événement status_transition en base
    $event = $tx->events()->where('event_type', 'status_transition')->latest('id')->first();
    expect($event)->not->toBeNull();

    $payload = $event->payload;
    expect($payload)->toHaveKeys(['message', 'status', 'raw_response'])
        ->and($payload['message'])->toBe('Solde insuffisant')
        ->and($payload['status'])->toBe(0)
        ->and($payload['raw_response']['transaction']['error'])->toBe('INSUFFICIENT_FUNDS');
});

it('returns null failure_reason in PaymentTransactionResource when transaction is confirmed', function () {
    $user = User::factory()->create();

    $tx = PaymentTransaction::create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '5000.00',
        'fee_amount' => '100.00',
        'tax_amount' => '0.00',
        'total_amount' => '5100.00',
        'credit_used' => '0.00',
        'amount_charged' => '5100.00',
        'status' => PaymentStatus::Confirmed,
        'external_reference' => 'mb_pay_success',
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    $resource = (new PaymentTransactionResource($tx))->toArray(new Request);

    expect($resource['status'])->toBe(PaymentStatus::Confirmed->value)
        ->and($resource['failure_reason'])->toBeNull();
});
