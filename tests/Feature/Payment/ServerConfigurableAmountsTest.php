<?php

use App\Models\User;
use Database\Seeders\GeoSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('GET /api/catalog and GET /api/payments/catalog include settings in XAF for listing_boost and page_pro', function () {
    $this->seed(SubscriptionCatalogSeeder::class);
    $user = User::factory()->create();
    Passport::actingAs($user);

    // 1. Check GET /api/catalog (public subscription product catalog)
    $resCatalog = $this->getJson('/api/catalog');
    $resCatalog->assertOk();
    $products = $resCatalog->json('data');

    $boost = collect($products)->firstWhere('code', 'listing_boost');
    expect($boost)->not->toBeNull();
    expect($boost['settings'])->toBe([
        'min_budget' => 100,
        'max_budget' => 50000,
        'budget_step' => 500,
        'budget_steps' => [2000, 6000, 15000, 30000],
        'durations' => [3, 7, 14, 30],
        'operator_fee' => [
            'type' => 'percentage_with_min',
            'rate' => 0.02,
            'min' => 100,
        ],
    ]);

    $pagePro = collect($products)->firstWhere('code', 'page_pro');
    expect($pagePro)->not->toBeNull();
    expect($pagePro['settings'])->toBe([
        'free_corrections' => 2,
    ]);

    // 2. Check GET /api/payments/catalog
    $resPaymentsCatalog = $this->getJson('/api/payments/catalog');
    $resPaymentsCatalog->assertOk();
    $offers = $resPaymentsCatalog->json('data.offers');

    $boostOffer = collect($offers)->firstWhere('code', 'listing_boost');
    expect($boostOffer)->not->toBeNull();
    expect($boostOffer['settings'])->toBe([
        'min_budget' => 100,
        'max_budget' => 50000,
        'budget_step' => 500,
        'budget_steps' => [2000, 6000, 15000, 30000],
        'durations' => [3, 7, 14, 30],
        'operator_fee' => [
            'type' => 'percentage_with_min',
            'rate' => 0.02,
            'min' => 100,
        ],
    ]);
});

test('POST /api/payments/fees calculates 2% fee with min 100 FCFA', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // For 2000 XAF: 2% is 40 XAF, but minimum fee is 100 XAF -> fee = 100, total = 2100
    $res1 = $this->postJson('/api/payments/fees', [
        'amount' => 2000,
        'operator' => 'orange_money',
    ]);
    $res1->assertOk()
        ->assertJson([
            'fee' => 100.0,
            'total' => 2100.0,
            'amount' => 2000.0,
        ]);

    // For 10000 XAF: 2% is 200 XAF (> 100) -> fee = 200, total = 10200
    $res2 = $this->postJson('/api/payments/fees', [
        'amount' => 10000,
        'operator' => 'mtn_momo',
    ]);
    $res2->assertOk()
        ->assertJson([
            'fee' => 200.0,
            'total' => 10200.0,
            'amount' => 10000.0,
        ]);
});

test('GET /api/currencies returns currencies with rate_from_xaf and round_to', function () {
    $this->seed(GeoSeeder::class);

    $res = $this->getJson('/api/currencies');
    $res->assertOk();

    $currencies = $res->json();
    expect($currencies)->not->toBeEmpty();

    $xaf = collect($currencies)->firstWhere('code', 'XAF');
    expect($xaf)->not->toBeNull();
    expect((float) $xaf['rate_from_xaf'])->toEqual(1.0);
    expect($xaf['round_to'])->toBe(0);

    $eur = collect($currencies)->firstWhere('code', 'EUR');
    expect($eur)->not->toBeNull();
    expect($eur['rate_from_xaf'])->toBe(0.00152449);
    expect($eur['round_to'])->toBe(2);

    $gnf = collect($currencies)->firstWhere('code', 'GNF');
    expect($gnf)->not->toBeNull();
    expect($gnf['rate_from_xaf'])->toBe(14.2);
});
