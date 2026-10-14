<?php

use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Exceptions\Payment\IdempotencyConflictException;
use App\Exceptions\Payment\UnsupportedGatewayException;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PricingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    config([
        'payment.default_gateway' => 'monetbil',
        'payment.gateways.monetbil.enabled' => true,
        'payment.gateways.monetbil.service_key' => 'test_key',
        'payment.gateways.monetbil.fee_percentage' => 2.0,
        'payment.gateways.monetbil.fee_fixed' => 0.0,
        'payment.gateways.monetbil.min_fee' => 100.0,
    ]);
});

it('creates a transaction and preserves immutable snapshot metadata', function () {
    $user = User::factory()->create();
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);
    $idempotencyKey = 'idemp_'.Str::uuid();

    $tx = $engine->createOrRetrieveTransaction($user, $quote, $idempotencyKey, [
        'device' => 'iPhone 15 Pro',
        'app_version' => '1.2.0',
    ]);

    expect($tx->exists)->toBeTrue()
        ->and($tx->user_id)->toBe($user->id)
        ->and($tx->status)->toBe(PaymentStatus::Pending)
        ->and($tx->idempotency_key)->toBe($idempotencyKey)
        ->and($tx->metadata['device'])->toBe('iPhone 15 Pro')
        ->and($tx->metadata['pricing_snapshot']['base_amount'])->toBe('7500.00');
});

it('returns existing transaction on compatible idempotent replay', function () {
    $user = User::factory()->create();
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);
    $idempotencyKey = 'idemp_stable_123';

    // Premier appel
    $tx1 = $engine->createOrRetrieveTransaction($user, $quote, $idempotencyKey);

    // Répétition avec contenu identique
    $tx2 = $engine->createOrRetrieveTransaction($user, $quote, $idempotencyKey);

    expect($tx2->id)->toBe($tx1->id)
        ->and($tx2->reference)->toBe($tx1->reference)
        ->and(PaymentTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

it('rejects idempotent replay with mismatched payload with IdempotencyConflictException', function () {
    $user = User::factory()->create();
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $landlord = SubscriptionProduct::where('code', 'landlord')->first();
    $agent = SubscriptionProduct::where('code', 'agent')->first();

    $quoteLandlord = $pricing->calculateQuote(PaymentServiceType::Subscription, $landlord->prices()->first()->id);
    $quoteAgent = $pricing->calculateQuote(PaymentServiceType::Subscription, $agent->prices()->first()->id);

    $idempotencyKey = 'reused_key_conflict';

    // Premier appel avec Landlord (7 500 XAF)
    $engine->createOrRetrieveTransaction($user, $quoteLandlord, $idempotencyKey);

    // Deuxième appel réutilisant la même clé avec Agent (3 500 XAF)
    expect(fn () => $engine->createOrRetrieveTransaction($user, $quoteAgent, $idempotencyKey))
        ->toThrow(IdempotencyConflictException::class);
});

it('fulfills confirmed transaction by connecting to SubscriptionService without bypass', function () {
    $user = User::factory()->create(['is_verified' => true]);
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);
    $tx = $engine->createOrRetrieveTransaction($user, $quote, 'idemp_'.Str::uuid());

    expect($tx->status)->toBe(PaymentStatus::Pending)
        ->and($tx->subscription_id)->toBeNull();

    // Confirmation serveur après webhook ou vérification de passerelle
    $confirmedTx = $engine->confirmAndFulfill(
        transaction: $tx,
        externalReference: 'MB_SUCCESS_999888',
        source: PaymentEventSource::Gateway,
        fulfillmentDetails: ['operator' => 'CM_ORANGEMONEY']
    );

    expect($confirmedTx->status)->toBe(PaymentStatus::Confirmed)
        ->and($confirmedTx->confirmed_at)->not->toBeNull()
        ->and($confirmedTx->external_reference)->toBe('MB_SUCCESS_999888')
        ->and($confirmedTx->subscription_id)->not->toBeNull();

    // Vérifier l'abonnement créé dans SUB
    $subscription = Subscription::find($confirmedTx->subscription_id);
    expect($subscription)->not->toBeNull()
        ->and($subscription->user_id)->toBe($user->id)
        ->and($subscription->badge_type)->toBe('landlord')
        ->and($subscription->state)->toBe('active')
        ->and($subscription->payment_reference)->toBe($confirmedTx->reference);

    // Badge synchronisé sur l'utilisateur
    $user->refresh();
    expect($user->badge)->toBe('landlord');
});

it('prevents multiple activations on subsequent confirmAndFulfill calls', function () {
    $user = User::factory()->create(['is_verified' => true]);
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);
    $tx = $engine->createOrRetrieveTransaction($user, $quote, 'idemp_'.Str::uuid());

    // 1ère confirmation
    $tx1 = $engine->confirmAndFulfill($tx, 'EXT_REF_01');
    $initialSubscriptionId = $tx1->subscription_id;
    $initialCount = Subscription::where('user_id', $user->id)->count();

    // 2ème confirmation redondante (ex: webhook réémis)
    $tx2 = $engine->confirmAndFulfill($tx1, 'EXT_REF_01');

    expect($tx2->subscription_id)->toBe($initialSubscriptionId)
        ->and(Subscription::where('user_id', $user->id)->count())->toBe($initialCount);
});

it('rejects confirmation on cancelled or failed transaction', function () {
    $user = User::factory()->create();
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->first();

    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);
    $tx = $engine->createOrRetrieveTransaction($user, $quote, 'idemp_'.Str::uuid());

    // Annulation
    $engine->cancelTransaction($tx, PaymentEventSource::User, 'Annulé par l\'utilisateur');

    // Tentative de confirmation d'une transaction annulée
    expect(fn () => $engine->confirmAndFulfill($tx, 'EXT_REF_LATE'))
        ->toThrow(DomainException::class);
});

it('resolves active gateway through manager and rejects disabled gateways without fallback', function () {
    $manager = app(PaymentGatewayManager::class);

    // 1. Passerelle Monetbil active par défaut
    $driver = $manager->driver('monetbil');
    expect($driver->getIdentifier())->toBe('monetbil');

    // 2. Passerelle Flutterwave désactivée -> Exception stricte, pas de fallback silencieux
    expect(fn () => $manager->driver('flutterwave'))
        ->toThrow(UnsupportedGatewayException::class);

    // 3. Passerelle inconnue
    expect(fn () => $manager->driver('unknown_gateway'))
        ->toThrow(UnsupportedGatewayException::class);
});
