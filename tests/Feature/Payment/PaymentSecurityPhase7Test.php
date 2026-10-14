<?php

namespace Tests\Feature\Payment;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\Payment\StorePurchaseReceipt;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\Gateways\MonetbilGateway;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PricingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
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
        'payment.gateways.monetbil.service_key' => 'test_service_key_p7',
        'payment.gateways.monetbil.service_secret' => 'test_service_secret_p7',
        'payment.gateways.monetbil.api_base_url' => 'https://api.monetbil.com',
        'payment.reconciliation.min_age_minutes' => 0, // pour faciliter les tests
    ]);

    $this->user = User::factory()->create(['is_verified' => true]);
    $this->product = SubscriptionProduct::where('code', 'landlord')->first();
    $this->price = $this->product->prices()->where('cadence', 'monthly')->first();
});

function p7Sign(array $params, string $secret = 'test_service_secret_p7'): string
{
    unset($params['sign']);
    ksort($params);

    return md5($secret.implode('', $params));
}

// --------------------------------------------------------------------------
// 1. Signature IPN invalide
// --------------------------------------------------------------------------
it('1. rejects IPN webhook with invalid or forged signature with 403 Forbidden', function () {
    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_fake_01',
        'status' => 'success',
        'amount' => '7650',
        'sign' => 'invalid_signature_hash',
    ];

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(403)
        ->assertJson([
            'status' => 'error',
            'message' => 'Signature invalide.',
        ]);
});

// --------------------------------------------------------------------------
// 2. Notification mal formée
// --------------------------------------------------------------------------
it('2. rejects malformed IPN missing transaction references or invalid amount with 422', function () {
    $params = [
        'service' => 'test_service_key_p7',
        'status' => 'success',
        // manquent paymentId et item_ref
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(422)
        ->assertJson(['status' => 'error']);

    // Montant manquant
    $params2 = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_test_02',
    ];
    $params2['sign'] = p7Sign($params2);

    postJson('/api/webhooks/payments/monetbil', $params2)
        ->assertStatus(422);
});

// --------------------------------------------------------------------------
// 3. Référence externe inconnue
// --------------------------------------------------------------------------
it('3. returns 404 when IPN refers to an unknown transaction reference', function () {
    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_unknown_ref',
        'item_ref' => 'BNG-TX-DOES-NOT-EXIST',
        'status' => 'success',
        'amount' => '7650',
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(404)
        ->assertJson([
            'status' => 'error',
            'message' => 'Transaction introuvable.',
        ]);
});

// --------------------------------------------------------------------------
// 4. Montant incorrect (fraude détectée)
// --------------------------------------------------------------------------
it('4. rejects IPN with incorrect amount and logs fraud alert event', function () {
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->user->id,
        'total_amount' => '7650.00',
        'currency' => 'XAF',
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'item_ref' => $tx->reference,
        'paymentId' => 'mb_pay_tamper_amt',
        'status' => 'success',
        'amount' => '500.00', // Altéré
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'Montant incohérent.',
        ]);

    expect($tx->events()->where('event_type', 'fraud_alert')->exists())->toBeTrue();
});

// --------------------------------------------------------------------------
// 5. Devise incorrecte
// --------------------------------------------------------------------------
it('5. rejects IPN with incorrect currency and logs fraud alert event', function () {
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->user->id,
        'total_amount' => '7650.00',
        'currency' => 'XAF',
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'item_ref' => $tx->reference,
        'paymentId' => 'mb_pay_tamper_curr',
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'EUR', // Altéré
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(422)
        ->assertJson([
            'status' => 'error',
            'message' => 'Devise incohérente.',
        ]);

    expect($tx->events()->where('event_type', 'fraud_alert')->exists())->toBeTrue();
});

// --------------------------------------------------------------------------
// 6. Vérification Monetbil réussie
// --------------------------------------------------------------------------
it('6. fulfills subscription atomically upon successful Monetbil server verification', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
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

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'OM_TX_998877',
                'operator' => 'CM_ORANGEMONEY',
            ],
        ], 200),
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_succ_06',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'XAF',
        'operator' => 'CM_ORANGEMONEY',
        'operator_transaction_id' => 'OM_TX_998877',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(200)
        ->assertJson(['status' => 'success']);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed)
        ->and($tx->subscription_id)->not->toBeNull();

    $sub = Subscription::find($tx->subscription_id);
    expect($sub->state)->toBe('active')
        ->and($this->user->fresh()->badge)->toBe('landlord');
});

// --------------------------------------------------------------------------
// 7. Vérification Monetbil échouée
// --------------------------------------------------------------------------
it('7. marks transaction as failed and does not activate rights when checkPayment fails', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
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

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 0,
                'message' => 'Solde insuffisant',
            ],
        ], 200),
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_fail_07',
        'item_ref' => $tx->reference,
        'status' => 'failed',
        'amount' => '7650.00',
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(200);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Failed)
        ->and($tx->subscription_id)->toBeNull()
        ->and(in_array($this->user->fresh()->badge, [null, 'none'], true))->toBeTrue();
});

// --------------------------------------------------------------------------
// 8. Timeout réseau avec conservation d'un statut incertain (Unknown)
// --------------------------------------------------------------------------
it('8. preserves uncertain unknown status during network timeout without declaring premature failure', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
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

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => function () {
            throw new ConnectionException('Connection timed out after 5000ms');
        },
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_timeout_08',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(200)
        ->assertJson(['status' => 'received']);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Unknown)
        ->and($tx->subscription_id)->toBeNull();
});

// --------------------------------------------------------------------------
// 9. IPN dupliqué (Idempotence)
// --------------------------------------------------------------------------
it('9. responds idempotently to duplicated IPN without creating duplicate events or subscriptions', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'credit_used' => '0.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Confirmed, // Déjà confirmé
        'confirmed_at' => now(),
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    $initialEventCount = $tx->events()->count();

    $params = [
        'service' => 'test_service_key_p7',
        'item_ref' => $tx->reference,
        'paymentId' => 'mb_pay_dup_09',
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    // Envoi du duplicata
    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'message' => 'Paiement déjà confirmé.',
        ]);

    expect($tx->events()->count())->toBe($initialEventCount);
});

// --------------------------------------------------------------------------
// 10. Deux traitements concurrents d'une même transaction
// --------------------------------------------------------------------------
it('10. handles concurrent processing attempts atomically with database lock', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
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

    $engine = app(PaymentEngineService::class);

    // Exécution de deux confirmations consécutives sous transaction
    $first = $engine->confirmAndFulfill($tx, 'ext_ref_10', PaymentEventSource::Gateway);
    $second = $engine->confirmAndFulfill($tx, 'ext_ref_10', PaymentEventSource::Gateway);

    expect($first->status)->toBe(PaymentStatus::Confirmed)
        ->and($second->status)->toBe(PaymentStatus::Confirmed)
        ->and($tx->events()->where('event_type', 'subscription_fulfilled')->count())->toBe(1);
});

// --------------------------------------------------------------------------
// 11. Succès confirmé après un état unknown
// --------------------------------------------------------------------------
it('11. successfully confirms and fulfills subscription after an initial unknown state', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'credit_used' => '0.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Unknown, // État préalable
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'OM_RECOVERED_11',
                'operator' => 'CM_ORANGEMONEY',
            ],
        ], 200),
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_unk_to_succ_11',
        'item_ref' => $tx->reference,
        'status' => 'success',
        'amount' => '7650.00',
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(200);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed)
        ->and($tx->subscription_id)->not->toBeNull();
});

// --------------------------------------------------------------------------
// 12. Notification ancienne reçue après une confirmation
// --------------------------------------------------------------------------
it('12. ignores late negative notification arriving after confirmation without downgrading status', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'credit_used' => '0.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Confirmed,
        'confirmed_at' => now()->subMinutes(10),
        'idempotency_key' => 'idemp_'.Str::uuid(),
    ]);

    $params = [
        'service' => 'test_service_key_p7',
        'paymentId' => 'mb_pay_late_12',
        'item_ref' => $tx->reference,
        'status' => 'failed',
        'amount' => '7650.00',
        'currency' => 'XAF',
    ];
    $params['sign'] = p7Sign($params);

    postJson('/api/webhooks/payments/monetbil', $params)
        ->assertStatus(200);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed);
});

// --------------------------------------------------------------------------
// 13. Absence de double activation SUB
// --------------------------------------------------------------------------
it('13. prevents duplicate SUB subscription activation on multiple fulfillments', function () {
    $engine = app(PaymentEngineService::class);

    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
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

    $engine->confirmAndFulfill($tx, 'ext_ref_13', PaymentEventSource::Gateway);
    $subCountBefore = Subscription::where('user_id', $this->user->id)->count();

    // Deuxième appel idempotent
    $engine->confirmAndFulfill($tx, 'ext_ref_13', PaymentEventSource::Gateway);
    $subCountAfter = Subscription::where('user_id', $this->user->id)->count();

    expect($subCountBefore)->toBe(1)
        ->and($subCountAfter)->toBe(1);
});

// --------------------------------------------------------------------------
// 14. Paiement non confirmé ne donnant aucun droit premium
// --------------------------------------------------------------------------
it('14. does not grant premium badge or active rights when transaction is not confirmed', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
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

    expect(in_array($this->user->fresh()->badge, [null, 'none'], true))->toBeTrue()
        ->and(Subscription::where('user_id', $this->user->id)->count())->toBe(0);
});

// --------------------------------------------------------------------------
// 15. Isolation des transactions entre utilisateurs
// --------------------------------------------------------------------------
it('15. strictly isolates user transaction queries preventing cross-user access', function () {
    $userB = User::factory()->create();

    $txA = PaymentTransaction::factory()->create(['user_id' => $this->user->id]);
    $txB = PaymentTransaction::factory()->create(['user_id' => $userB->id]);

    Passport::actingAs($this->user, ['*']);

    // Utilisateur A accède au statut de sa transaction
    getJson("/api/payments/{$txA->id}/status")
        ->assertStatus(200);

    // Utilisateur A tente d'accéder au statut de la transaction de B (rejet sécurisé 404)
    getJson("/api/payments/{$txB->id}/status")
        ->assertStatus(404);
});

// --------------------------------------------------------------------------
// 16. Non-régression Laravel Passport et routes auth:api
// --------------------------------------------------------------------------
it('16. enforces Passport authentication on protected payment routes', function () {
    getJson('/api/payments/history')
        ->assertStatus(401);

    Passport::actingAs($this->user, ['*']);

    getJson('/api/payments/history')
        ->assertStatus(200);
});

// --------------------------------------------------------------------------
// 17. Non-régression du catalogue, des prix et des frais
// --------------------------------------------------------------------------
it('17. calculates quotes server-side preserving pricing rules and fees', function () {
    $pricingService = app(PricingService::class);

    $quote = $pricingService->calculateQuote(
        serviceType: PaymentServiceType::Subscription,
        priceId: $this->price->id,
        gateway: 'monetbil'
    );

    expect((float) $quote->amount)->toEqual(7500)
        ->and((float) $quote->totalAmount)->toBeGreaterThan(7500)
        ->and($quote->currency)->toBe('XAF');
});

// --------------------------------------------------------------------------
// 18. Non-régression des prestations certification, boost et visit
// --------------------------------------------------------------------------
it('18. fulfills non-subscription services with service_fulfilled event without creating subscriptions', function () {
    $engine = app(PaymentEngineService::class);

    foreach ([PaymentServiceType::Certification, PaymentServiceType::Boost, PaymentServiceType::Visit] as $serviceType) {
        $tx = PaymentTransaction::create([
            'user_id' => $this->user->id,
            'service_type' => $serviceType,
            'provider' => PaymentProvider::Monetbil,
            'channel' => PaymentChannel::MobileMoney,
            'currency' => 'XAF',
            'amount' => '2000.00',
            'total_amount' => '2100.00',
            'credit_used' => '0.00',
            'amount_charged' => '2100.00',
            'status' => PaymentStatus::Processing,
            'idempotency_key' => 'idemp_'.Str::uuid(),
        ]);

        $engine->confirmAndFulfill($tx, 'ext_ref_svc_'.$serviceType->value, PaymentEventSource::Gateway);

        $tx->refresh();
        expect($tx->status)->toBe(PaymentStatus::Confirmed)
            ->and($tx->subscription_id)->toBeNull()
            ->and($tx->events()->where('event_type', 'service_fulfilled')->exists())->toBeTrue()
            ->and($tx->events()->where('event_type', 'subscription_fulfilled')->exists())->toBeFalse();
    }
});

// --------------------------------------------------------------------------
// 19. Non-régression du renouvellement manuel et de la période de grâce
// --------------------------------------------------------------------------
it('19. preserves manual renewal and grace period rules from Phase 6', function () {
    $sub = Subscription::create([
        'user_id' => $this->user->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(30),
        'current_period_end' => now()->subDay(), // Échu
        'grace_until' => now()->addDays(6), // En grâce
    ]);

    Passport::actingAs($this->user, ['*']);

    $response = getJson("/api/subscriptions/{$sub->id}/renewal")
        ->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_eligible_for_renewal' => true,
            ],
        ]);

    expect($response->json('data.subscription.grace_until'))->not->toBeNull();
});

// --------------------------------------------------------------------------
// 20. Idempotence de la commande de réconciliation
// --------------------------------------------------------------------------
it('20. runs reconciliation command idempotently without duplicate effects', function () {
    $tx = PaymentTransaction::create([
        'user_id' => $this->user->id,
        'service_type' => PaymentServiceType::Subscription,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'currency' => 'XAF',
        'amount' => '7500.00',
        'total_amount' => '7650.00',
        'credit_used' => '0.00',
        'amount_charged' => '7650.00',
        'status' => PaymentStatus::Processing,
        'external_reference' => 'mb_pay_reconcile_20',
        'idempotency_key' => 'idemp_'.Str::uuid(),
        'created_at' => now()->subMinutes(10),
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/checkPayment' => Http::response([
            'transaction' => [
                'status' => 1,
                'operator_transaction_id' => 'OM_RECONCILED_20',
            ],
        ], 200),
    ]);

    // Première exécution : confirme la transaction
    $exitCode1 = Artisan::call('payment:reconcile');
    expect($exitCode1)->toBe(0);

    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed);

    // Seconde exécution : aucun doublon
    $exitCode2 = Artisan::call('payment:reconcile');
    expect($exitCode2)->toBe(0);

    expect(Subscription::where('user_id', $this->user->id)->count())->toBe(1);
});

// --------------------------------------------------------------------------
// 21. Protection des secrets dans les réponses et les journaux
// --------------------------------------------------------------------------
it('21. never leaks service secrets or passwords in API responses', function () {
    Passport::actingAs($this->user, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->user->id,
        'metadata' => [
            'service_secret' => 'super_confidential_secret',
            'phone' => '237699000000',
        ],
    ]);

    $response = getJson("/api/payments/{$tx->id}/status")
        ->assertStatus(200);

    $content = $response->getContent();
    expect($content)->not->toContain('super_confidential_secret')
        ->and($content)->not->toContain('test_service_secret_p7');
});

// --------------------------------------------------------------------------
// 22. Préparation des métadonnées pour les futurs achats Apple et Google
// --------------------------------------------------------------------------
it('22. stores store purchase receipt with unique SHA-256 token hash preventing replay', function () {
    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->user->id,
        'provider' => PaymentProvider::Apple,
        'channel' => PaymentChannel::AppleIap,
    ]);

    $rawReceipt = 'MIIbPAYLOAD_FROM_STOREKIT_ENCRYPTED_RECEIPT_TOKEN_XYZ';
    $tokenHash = StorePurchaseReceipt::hashToken($rawReceipt);

    $receipt = StorePurchaseReceipt::create([
        'transaction_id' => $tx->id,
        'provider' => 'apple',
        'store_product_id' => 'cm.bango.subscription.landlord.monthly',
        'original_transaction_id' => '1000000123456789',
        'purchase_token_hash' => $tokenHash,
        'verification_status' => 'pending',
        'environment' => 'sandbox',
        'store_payload' => ['bundle_id' => 'cm.bango.app'],
    ]);

    expect($receipt->id)->not->toBeNull()
        ->and($receipt->purchase_token_hash)->toBe(hash('sha256', $rawReceipt))
        ->and($tx->storeReceipt->id)->toBe($receipt->id);

    // Tentative de rejeu du même token pour une autre transaction -> rejet par contrainte d'unicité
    $tx2 = PaymentTransaction::factory()->create(['user_id' => $this->user->id]);

    expect(function () use ($tx2, $tokenHash) {
        StorePurchaseReceipt::create([
            'transaction_id' => $tx2->id,
            'provider' => 'apple',
            'store_product_id' => 'cm.bango.subscription.landlord.monthly',
            'purchase_token_hash' => $tokenHash, // Déjà consommé
        ]);
    })->toThrow(QueryException::class);
});
