<?php

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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

it('1. rejects unauthenticated access to all payment endpoints', function () {
    getJson('/api/payments/catalog')->assertStatus(401);
    postJson('/api/payments/quote', [])->assertStatus(401);
    postJson('/api/payments/initiate', [])->assertStatus(401);
    getJson('/api/payments/1/status')->assertStatus(401);
    postJson('/api/payments/1/cancel')->assertStatus(401);
    getJson('/api/payments/history')->assertStatus(401);
});

it('2. provides catalog of offers and services with transparent pricing breakdown', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = getJson('/api/payments/catalog')
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'currency' => 'XAF',
                'gateway' => 'monetbil',
            ],
        ]);

    $data = $response->json('data');
    expect($data['offers'])->not->toBeEmpty()
        ->and($data['services'])->not->toBeEmpty();

    // Vérifier les offres d'abonnement actives (landlord, agent)
    $landlord = collect($data['offers'])->firstWhere('code', 'landlord');
    expect($landlord)->not->toBeNull()
        ->and($landlord['is_purchasable'])->toBeTrue()
        ->and($landlord['prices'])->toHaveCount(2);

    // Vérifier que page_pro et listing_boost sont en coming_soon
    $pagePro = collect($data['offers'])->firstWhere('code', 'page_pro');
    expect($pagePro)->not->toBeNull()
        ->and($pagePro['is_purchasable'])->toBeFalse()
        ->and($pagePro['availability_status'])->toBe('coming_soon');
});

it('3 & 4 & 5. calculates certified server-side quote with gateway fees added to base amount', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first(); // 7500 XAF

    $response = postJson('/api/payments/quote', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
    ])->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'service_type' => 'subscription',
                'price_id' => $price->id,
                'cadence' => 'monthly',
                'currency' => 'XAF',
                'base_amount' => 7500.0,
                'fee_amount' => 150.0, // 2% de 7500
                'tax_amount' => 0.0,
                'total_amount' => 7650.0,
                'gateway' => 'monetbil',
                'purchasable' => true,
            ],
        ]);

    $data = $response->json('data');
    expect($data)->toHaveKeys(['quote_token', 'expires_at', 'breakdown'])
        ->and($data['breakdown']['billed_to_customer'])->toBeTrue();
});

it('6. detects price modification or quote expiration during payment initiation', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first(); // 7500 XAF (total 7650 XAF)

    // A. Prix modifié côté client (tentative d'altération ou tarif antérieur)
    $response = postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'idempotency_key' => 'quote_check_1',
        'expected_total' => 5000.0, // Altération
    ])->assertStatus(409)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'PRICE_MODIFIED',
            ],
        ]);

    // B. Devis expiré
    postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'idempotency_key' => 'quote_check_2',
        'quote_expires_at' => now()->subMinutes(5)->toIso8601String(),
    ])->assertStatus(409)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'QUOTE_EXPIRED',
            ],
        ]);
});

it('7 & 8. creates payment intent idempotently and does not prematurely activate subscription', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_pay_active_999',
            'channel_ussd' => '#150*50#',
        ], 200),
    ]);

    $idempotencyKey = 'unique_sub_order_42';

    // 1ère initiation
    $res1 = postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'operator' => 'orange',
        'idempotency_key' => $idempotencyKey,
    ])->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'processing',
                'amount' => 7500,
                'totalAmount' => 7650,
            ],
        ]);

    $txId = $res1->json('data.id');

    // 13. Aucun abonnement ne doit être activé à ce stade !
    expect(Subscription::where('user_id', $user->id)->count())->toBe(0);

    // 2ème appel identique (rejeu idempotent)
    $res2 = postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'operator' => 'orange',
        'idempotency_key' => $idempotencyKey,
    ])->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $txId,
                'status' => 'processing',
            ],
        ]);

    // Une seule transaction et aucun abonnement créé
    expect(PaymentTransaction::count())->toBe(1)
        ->and(Subscription::count())->toBe(0);
});

it('9. rejects reused idempotency key with conflicting parameters (EDGE-17)', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $prices = $product->prices()->get();

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_pay_conflict_1',
        ], 200),
    ]);

    $key = 'reused_key_conflict';

    // Premier appel avec le tarif mensuel
    postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $prices[0]->id,
        'phone' => '699112233',
        'idempotency_key' => $key,
    ])->assertStatus(201);

    // Deuxième appel avec la MÊME clé mais un prix différent (annuel)
    postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $prices[1]->id,
        'phone' => '699112233',
        'idempotency_key' => $key,
    ])->assertStatus(409)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'IDEMPOTENCY_CONFLICT',
            ],
        ]);
});

it('10 & 11. enforces user isolation for transaction status check', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    // Transaction appartenant à User 1
    $transaction = PaymentTransaction::factory()->create([
        'user_id' => $user1->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'status' => PaymentStatus::Processing,
        'external_reference' => 'mb_user1_tx',
    ]);

    // User 2 tente de consulter la transaction de User 1 -> 404 Not Found
    Passport::actingAs($user2);
    getJson("/api/payments/{$transaction->id}/status")
        ->assertStatus(404)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'TRANSACTION_NOT_FOUND',
            ],
        ]);

    // User 1 consulte sa propre transaction -> 200 OK
    Passport::actingAs($user1);
    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'status' => '-1',
            'message' => 'Pending',
        ], 200),
    ]);

    getJson("/api/payments/{$transaction->id}/status")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => (string) $transaction->id,
                'status' => 'processing',
            ],
        ]);
});

it('12. paginates personal history with multi-criteria filters', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();

    // Créer 5 transactions de souscription et 2 de visite
    PaymentTransaction::factory()->count(5)->create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $product->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    PaymentTransaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Visit,
        'status' => PaymentStatus::Cancelled,
    ]);

    // Test filtre par statut
    $resStatus = getJson('/api/payments/history?status=confirmed')
        ->assertStatus(200);
    expect($resStatus->json('data'))->toHaveCount(5);

    // Test filtre par service_type
    $resType = getJson('/api/payments/history?service_type=visit')
        ->assertStatus(200);
    expect($resType->json('data'))->toHaveCount(2);

    // Test pagination
    $resPage = getJson('/api/payments/history?per_page=3')
        ->assertStatus(200);
    expect($resPage->json('data'))->toHaveCount(3)
        ->and($resPage->json('meta.total'))->toBe(7);
});

it('14 & 15. activates SUB only upon verified confirmation with absolute idempotency', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $transaction = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'status' => PaymentStatus::Processing,
        'external_reference' => 'mb_confirm_sub_test',
        'total_amount' => '7650.00',
    ]);

    // Mock Monetbil checkPayment confirmant le paiement
    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'status' => 1,
            'message' => 'Success',
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'OM_TX_CONFIRM_789',
            ],
        ], 200),
    ]);

    // Premier appel status: déclenche la confirmation serveur et l'activation SUB
    getJson("/api/payments/{$transaction->id}/status")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'confirmed',
                'charged' => true,
            ],
        ]);

    expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
    $sub = Subscription::where('user_id', $user->id)->first();
    expect($sub->state)->toBe('active')
        ->and($sub->badge_type)->toBe('landlord');

    // Deuxième appel de vérification (rejeu concurrent ou polling ultérieur)
    getJson("/api/payments/{$transaction->id}/status")
        ->assertStatus(200);

    // Idempotence absolue : toujours 1 seul abonnement
    expect(Subscription::where('user_id', $user->id)->count())->toBe(1);
});

it('16. handles gateway timeout and provider unavailability gracefully (EDGE-19)', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    // Simuler un timeout HTTP
    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => function () {
            throw new ConnectionException('Connection timed out after 5000ms');
        },
    ]);

    postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'idempotency_key' => 'timeout_test_key',
    ])->assertStatus(504)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'GATEWAY_UNAVAILABLE',
                'retryable' => true,
            ],
        ]);
});

it('17. rejects certification and boost purchase without granting fictitious rights', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Tentative d'initier certification
    postJson('/api/payments/initiate', [
        'service_type' => 'certification',
        'phone' => '699112233',
        'idempotency_key' => 'cert_order_1',
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'SERVICE_NOT_AVAILABLE',
                'details' => ['service_type' => 'certification'],
            ],
        ]);

    // Tentative d'initier boost
    postJson('/api/payments/initiate', [
        'service_type' => 'boost',
        'phone' => '699112233',
        'idempotency_key' => 'boost_order_1',
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'SERVICE_NOT_AVAILABLE',
                'details' => ['service_type' => 'boost'],
            ],
        ]);
});

it('18. enforces KYC integrity and blocks redundant subscriptions for already active badges', function () {
    $user = User::factory()->create(['is_verified' => false]);
    Passport::actingAs($user);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    // Créer un abonnement existant déjà actif pour ce badge
    Subscription::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => '7500.00',
        'currency' => 'XAF',
        'state' => 'active',
        'started_at' => now(),
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(20),
        'payment_reference' => 'PREV_PAID_123',
    ]);

    // Tentative de souscrire à nouveau au même badge
    postJson('/api/payments/initiate', [
        'service_type' => 'subscription',
        'price_id' => $price->id,
        'phone' => '699112233',
        'idempotency_key' => 'redundant_sub_key',
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'SERVICE_NOT_ELIGIBLE',
                'details' => [
                    'badge_type' => 'landlord',
                ],
            ],
        ]);
});

it('19. allows manual cancellation of pending transaction and prevents cancellation of confirmed transaction', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Transaction en cours -> Annulable
    $pendingTx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'status' => PaymentStatus::Processing,
    ]);

    postJson("/api/payments/{$pendingTx->id}/cancel")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'cancelled',
            ],
        ]);

    expect($pendingTx->fresh()->status)->toBe(PaymentStatus::Cancelled);

    // Transaction confirmée -> NON annulable
    $confirmedTx = PaymentTransaction::factory()->create([
        'user_id' => $user->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    postJson("/api/payments/{$confirmedTx->id}/cancel")
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'INVALID_STATE',
            ],
        ]);
});
