<?php

use App\Enums\Payment\PaymentServiceType;
use App\Exceptions\Payment\InvalidPriceException;
use App\Exceptions\Payment\MissingPricingConfigurationException;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\PaymentEngineService;
use App\Services\Payment\PricingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    // Configuration explicite des frais Monetbil pour les tests
    config([
        'payment.default_gateway' => 'monetbil',
        'payment.gateways.monetbil.enabled' => true,
        'payment.gateways.monetbil.service_key' => 'test_key',
        'payment.gateways.monetbil.fee_type' => 'percentage_with_min',
        'payment.gateways.monetbil.fee_percentage' => 2.0,
        'payment.gateways.monetbil.fee_fixed' => 0.0,
        'payment.gateways.monetbil.min_fee' => 100.0,
        'payment.tax.enabled' => false,
    ]);
});

it('calculates subscription price, fees and total accurately for Landlord monthly', function () {
    $pricing = app(PricingService::class);
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    $quote = $pricing->calculateQuote(
        serviceType: PaymentServiceType::Subscription,
        priceId: $price->id
    );

    // 7 500 * 2% = 150.00 (> 100 min_fee)
    // Total = 7 500 + 150 = 7 650.00
    expect($quote->amount)->toBe('7500.00')
        ->and($quote->feeAmount)->toBe('150.00')
        ->and($quote->taxAmount)->toBe('0.00')
        ->and($quote->totalAmount)->toBe('7650.00')
        ->and($quote->currency)->toBe('XAF')
        ->and($quote->gateway)->toBe('monetbil')
        ->and($quote->snapshot)->toHaveKeys(['pricing_version', 'base_amount', 'fee_amount', 'total_amount']);
});

it('applies minimum fee threshold when percentage fee is lower', function () {
    $pricing = app(PricingService::class);
    $product = SubscriptionProduct::where('code', 'agent')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    // 3 500 * 2% = 70.00, but min_fee is 100.00
    // Fee = 100.00, Total = 3 500 + 100 = 3 600.00
    $quote = $pricing->calculateQuote(
        serviceType: PaymentServiceType::Subscription,
        priceId: $price->id
    );

    expect($quote->amount)->toBe('3500.00')
        ->and($quote->feeAmount)->toBe('100.00')
        ->and($quote->totalAmount)->toBe('3600.00');
});

it('calculates price for Certification Page Pro, Boost and Visit services', function () {
    $pricing = app(PricingService::class);

    // 1. Certification Page Pro (one_off 15 000 XAF) -> fee 2% = 300 -> total 15 300
    $certQuote = $pricing->calculateQuote(PaymentServiceType::Certification);
    expect($certQuote->amount)->toBe('15000.00')
        ->and($certQuote->feeAmount)->toBe('300.00')
        ->and($certQuote->totalAmount)->toBe('15300.00');

    // 2. Listing Boost (2 180 XAF) -> fee 2% = 43.60 -> min_fee 100.00 -> total 2 280
    $boostQuote = $pricing->calculateQuote(PaymentServiceType::Boost);
    expect($boostQuote->amount)->toBe('2180.00')
        ->and($boostQuote->feeAmount)->toBe('100.00')
        ->and($boostQuote->totalAmount)->toBe('2280.00');

    // 3. Visit (custom amount, ex: 5 000 XAF) -> fee 2% = 100 -> total 5 100
    $visitQuote = $pricing->calculateQuote(
        serviceType: PaymentServiceType::Visit,
        customAmount: 5000.00
    );
    expect($visitQuote->amount)->toBe('5000.00')
        ->and($visitQuote->feeAmount)->toBe('100.00')
        ->and($visitQuote->totalAmount)->toBe('5100.00');
});

it('includes tax calculation when tax is enabled in configuration', function () {
    config([
        'payment.tax.enabled' => true,
        'payment.tax.rate_percentage' => 19.25, // TVA 19.25%
    ]);

    $pricing = app(PricingService::class);
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);

    // Base: 7500.00
    // Fee 2%: 150.00
    // Tax 19.25% of 7500: 1443.75
    // Total: 7500 + 150 + 1443.75 = 9093.75
    expect($quote->amount)->toBe('7500.00')
        ->and($quote->feeAmount)->toBe('150.00')
        ->and($quote->taxAmount)->toBe('1443.75')
        ->and($quote->totalAmount)->toBe('9093.75');
});

it('refuses to calculate quote if gateway fee configuration is missing', function () {
    // Supprimer la configuration de frais pour tester le refus strict
    config(['payment.gateways.monetbil.fee_percentage' => null]);

    $pricing = app(PricingService::class);
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    expect(fn () => $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id))
        ->toThrow(MissingPricingConfigurationException::class);
});

it('refuses to calculate quote if price is inactive or expired', function () {
    $pricing = app(PricingService::class);
    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    // 1. Désactivation du tarif
    $price->update(['is_active' => false]);
    expect(fn () => $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id))
        ->toThrow(InvalidPriceException::class);

    // 2. Expiration de la date d'effet
    $price->update(['is_active' => true, 'effective_until' => Carbon::now()->subDay()]);
    expect(fn () => $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id))
        ->toThrow(InvalidPriceException::class);
});

it('preserves historical transaction price and snapshot when catalog changes later', function () {
    $user = User::factory()->create();
    $pricing = app(PricingService::class);
    $engine = app(PaymentEngineService::class);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices()->where('cadence', 'monthly')->first();

    // Création initiale avec le tarif courant (7 500 XAF)
    $quote = $pricing->calculateQuote(PaymentServiceType::Subscription, $price->id);
    $tx = $engine->createOrRetrieveTransaction($user, $quote, 'idemp_'.Str::uuid());

    expect($tx->amount)->toBe('7500.00')
        ->and($tx->total_amount)->toBe('7650.00');

    // Modification ultérieure du prix dans le catalogue (passage à 10 000 XAF)
    $price->update(['price_amount' => 10000.00]);

    // La transaction existante doit préserver son montant d'origine
    $tx->refresh();
    expect($tx->amount)->toBe('7500.00')
        ->and($tx->total_amount)->toBe('7650.00')
        ->and($tx->metadata['pricing_snapshot']['base_amount'])->toBe('7500.00');
});
