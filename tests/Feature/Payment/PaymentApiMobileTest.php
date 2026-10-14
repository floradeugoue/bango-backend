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
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
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
        'payment.gateways.monetbil.fee_percentage' => 2.0,
        'payment.gateways.monetbil.fee_fixed' => 0.0,
        'payment.gateways.monetbil.min_fee' => 100.0,
        'payment.timeout_seconds' => 120,
    ]);
});

it('rejects unauthenticated requests on payment endpoints', function () {
    postJson('/api/payments/initiate', [])->assertStatus(401);
    getJson('/api/payments/1/status')->assertStatus(401);
    postJson('/api/payments/1/cancel')->assertStatus(401);
    getJson('/api/payments/history')->assertStatus(401);
});

it('validates input parameters on initiate endpoint', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Missing price_id and phone and idempotency_key
    postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['phone', 'price_id', 'idempotency_key']);
});

it('initiates payment successfully via mobile endpoint and ignores client submitted amount', function () {
    $user = User::factory()->create(['is_verified' => true]);
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_pay_active_123',
            'channel_ussd' => '#150*50#',
            'operator' => 'CM_ORANGEMONEY',
        ], 200),
    ]);

    $idempotencyKey = 'idemp_mobile_'.Str::uuid();

    // Client tente d'envoyer amount = 50 FCFA (tentative de fraude ou falsification)
    $response = postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'amount' => 50,
        'phone' => '699112233',
        'operator' => 'CM_ORANGEMONEY',
        'idempotency_key' => $idempotencyKey,
    ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'prompt' => [
                'ussd' => '#150*50#',
                'timeout_seconds' => 120,
            ],
        ]);

    $data = $response->json('data');
    expect($data['reference'])->toStartWith('BNG-TX-')
        ->and($data['amount'])->toEqual(7500)
        ->and($data['fee'])->toEqual(150)
        ->and($data['totalAmount'])->toEqual(7650)
        ->and($data['status'])->toBe(PaymentStatus::Processing->value);

    // Vérifier en base
    $tx = PaymentTransaction::where('idempotency_key', $idempotencyKey)->first();
    expect($tx)->not->toBeNull()
        ->and($tx->status)->toBe(PaymentStatus::Processing)
        ->and($tx->external_reference)->toBe('mb_pay_active_123')
        ->and($tx->attempts()->count())->toBe(1);
});

it('handles idempotent replay on initiate endpoint without duplicate calls', function () {
    $user = User::factory()->create(['is_verified' => true]);
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_pay_replay',
            'channel_ussd' => '#150*50#',
        ], 200),
    ]);

    $idempotencyKey = 'idemp_replay_unique';

    $payload = [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'idempotency_key' => $idempotencyKey,
    ];

    // 1er appel
    $res1 = postJson('/api/payments/initiate', $payload)->assertStatus(201);

    // 2ème appel identique (rejeu idempotent)
    $res2 = postJson('/api/payments/initiate', $payload)->assertStatus(200);

    expect($res2->json('data.id'))->toBe($res1->json('data.id'))
        ->and(PaymentTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(1);

    // Monetbil a été appelé une seule fois
    Http::assertSentCount(1);
});

it('protects transaction status access against other users (data isolation)', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $txAlice = PaymentTransaction::factory()->create(['user_id' => $alice->id]);

    // Bob tente de consulter la transaction d'Alice
    Passport::actingAs($bob);
    getJson("/api/payments/{$txAlice->id}/status")->assertStatus(404);

    // Alice consulte sa propre transaction
    Passport::actingAs($alice);
    getJson("/api/payments/{$txAlice->id}/status")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => (string) $txAlice->id,
                'reference' => $txAlice->reference,
            ],
        ]);
});

it('confirms payment and activates SUB subscription when polling status endpoint finds confirmed payment', function () {
    $user = User::factory()->create(['is_verified' => true]);
    Passport::actingAs($user);

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
        'external_reference' => 'mb_pay_poll_check',
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    // Monetbil checkPayment répond maintenant que le paiement a réussi
    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'MP_POLL_9988',
            ],
        ], 200),
    ]);

    $res = getJson("/api/payments/{$tx->id}/status");

    $res->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'confirmed',
                'operatorReference' => 'MP_POLL_9988',
            ],
        ]);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed)
        ->and($tx->subscription_id)->not->toBeNull();

    $sub = Subscription::find($tx->subscription_id);
    expect($sub->state)->toBe('active')
        ->and($sub->user_id)->toBe($user->id);

    // Badge synchronisé
    $user->refresh();
    expect($user->badge)->toBe('landlord');
});

it('allows customer to cancel a pending transaction and prevents cancelling a confirmed transaction', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // 1. Transaction en cours d'attente
    $pendingTx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'status' => PaymentStatus::Pending,
    ]);

    postJson("/api/payments/{$pendingTx->id}/cancel")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => ['status' => 'cancelled'],
        ]);

    $pendingTx->refresh();
    expect($pendingTx->status)->toBe(PaymentStatus::Cancelled);

    // 2. Transaction confirmée ne peut pas être annulée
    $confirmedTx = PaymentTransaction::factory()->confirmed()->create([
        'user_id' => $user->id,
    ]);

    postJson("/api/payments/{$confirmedTx->id}/cancel")
        ->assertStatus(422);
});

it('returns paginated payment history isolated to authenticated user without exposing secrets', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    PaymentTransaction::factory()->count(3)->create(['user_id' => $alice->id]);
    PaymentTransaction::factory()->count(2)->create(['user_id' => $bob->id]);

    Passport::actingAs($alice);

    $res = getJson('/api/payments/history');

    $res->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'reference',
                    'kind',
                    'amount',
                    'fee',
                    'totalAmount',
                    'currency',
                    'status',
                    'at',
                ],
            ],
            'meta' => ['current_page', 'total'],
        ]);

    expect(count($res->json('data')))->toBe(3);

    // Vérifier l'absence totale de secrets dans le payload JSON
    $content = $res->getContent();
    expect($content)->not->toContain('mock_service_secret')
        ->and($content)->not->toContain('service_key');
});
