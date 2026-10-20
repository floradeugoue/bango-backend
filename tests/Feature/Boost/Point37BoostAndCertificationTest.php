<?php

use App\Enums\Payment\PaymentServiceType;
use App\Models\Page\Page;
use App\Models\Square\Post;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Boost\CampaignEligibilityService;
use App\Services\Payment\Gateways\MonetbilGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create([
        'badge' => 'none',
        'is_verified' => false,
        'phone' => '+237699001122',
    ]);

    // Configurer le produit et prix pour le boost d'annonce
    $this->boostProduct = SubscriptionProduct::firstOrCreate(
        ['code' => 'listing_boost'],
        ['name' => 'Boost Annonce', 'is_active' => true]
    );
    $this->boostPrice = SubscriptionPrice::firstOrCreate(
        ['product_id' => $this->boostProduct->id, 'price_amount' => 2000],
        ['currency' => 'XAF', 'is_active' => true, 'cadence' => 'one_time', 'duration_days' => 7]
    );

    // Configurer le produit et prix pour la certification Page Pro
    $this->certProduct = SubscriptionProduct::firstOrCreate(
        ['code' => 'page_pro'],
        ['name' => 'Certification Page Pro', 'is_active' => true]
    );
    $this->certPrice = SubscriptionPrice::firstOrCreate(
        ['product_id' => $this->certProduct->id, 'price_amount' => 15000],
        ['currency' => 'XAF', 'is_active' => true, 'cadence' => 'one_time', 'duration_days' => 30]
    );
});

test('payments for certification and boost are not rejected with SERVICE_NOT_AVAILABLE', function (): void {
    Passport::actingAs($this->user);

    // 1. Quote pour Boost
    $boostQuoteResp = $this->postJson('/api/payments/quote', [
        'service_type' => PaymentServiceType::Boost->value,
        'price_id' => $this->boostPrice->id,
        'gateway' => 'monetbil',
    ]);
    $boostQuoteResp->assertOk();
    expect($boostQuoteResp->json('data.purchasable'))->toBeTrue();

    // 2. Quote pour Certification
    $certQuoteResp = $this->postJson('/api/payments/quote', [
        'service_type' => PaymentServiceType::Certification->value,
        'price_id' => $this->certPrice->id,
        'gateway' => 'monetbil',
    ]);
    $certQuoteResp->assertOk();
    expect($certQuoteResp->json('data.purchasable'))->toBeTrue();

    // 3. Initiate pour Boost ne renvoie pas SERVICE_NOT_AVAILABLE
    $mockGateway = Mockery::mock(MonetbilGateway::class);
    $mockGateway->shouldReceive('initiatePayment')->andReturn([
        'success' => true,
        'payment_url' => 'https://monetbil.com/pay',
        'transaction_id' => 'EXT-12345',
        'channel' => 'orange_money',
        'channel_ussd' => '*150#',
        'raw' => [],
    ]);
    $this->app->instance(MonetbilGateway::class, $mockGateway);

    $boostInitResp = $this->postJson('/api/payments/initiate', [
        'service_type' => PaymentServiceType::Boost->value,
        'price_id' => $this->boostPrice->id,
        'channel' => 'mobile_money',
        'operator' => 'orange',
        'phone' => '+237699001122',
        'idempotency_key' => 'BOOST-INIT-'.uniqid(),
    ]);

    $boostInitResp->assertCreated();
    expect($boostInitResp->json('data.service_type'))->toBe(PaymentServiceType::Boost->value);
});

test('boost targets content and does not require user to be a certified pro', function (): void {
    $eligibilityService = app(CampaignEligibilityService::class);

    // Publication appartenant à un utilisateur standard (sans badge pro ni certification)
    $post = Post::factory()->create([
        'user_id' => $this->user->id,
        'body' => 'Mon post a promouvoir',
    ]);

    $response = $eligibilityService->checkEligibility(
        user: $this->user,
        content: $post
    );

    expect($response->allowed())->toBeTrue();
});
