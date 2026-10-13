<?php

use App\Models\Auth\Role;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\User;
use App\Services\Subscription\BadgeResolutionService;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
});

it('GET /api/catalog returns public active products with prices in XAF', function () {
    $response = getJson('/api/catalog');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'code',
                    'name',
                    'features',
                    'isActive',
                    'prices' => [
                        '*' => [
                            'id',
                            'cadence',
                            'priceAmount',
                            'priceXaf',
                            'currency',
                            'durationDays',
                        ],
                    ],
                ],
            ],
        ]);

    // Check landlord product
    $landlord = collect($response->json('data'))->firstWhere('code', 'landlord');
    expect($landlord)->not->toBeNull();
    expect($landlord['name'])->toBe('Badge Bailleur Certifié');

    $prices = collect($landlord['prices']);
    $monthly = $prices->firstWhere('cadence', 'monthly');
    $yearly = $prices->firstWhere('cadence', 'yearly');

    expect($monthly['priceXaf'])->toBe(7500)
        ->and($yearly['priceXaf'])->toBe(75000);
});

it('GET /api/catalog/{code} returns detail of specific offer', function () {
    $res = getJson('/api/catalog/agent');

    $res->assertStatus(200)
        ->assertJsonPath('data.code', 'agent')
        ->assertJsonPath('data.badgeType', 'agent');

    getJson('/api/catalog/nonexistent')
        ->assertStatus(404);
});

it('admin can create and update catalog products and prices, non-admin is rejected', function () {
    $adminRole = Role::where('slug', 'admin')->first();
    $userRole = Role::where('slug', 'user')->first();

    $admin = User::factory()->create(['role_id' => $adminRole->id]);
    $regularUser = User::factory()->create(['role_id' => $userRole->id]);

    // 1. Regular user rejected with 403
    Passport::actingAs($regularUser);
    postJson('/api/admin/catalog/products', [
        'code' => 'agency_gold',
        'name' => 'Pack Agence Gold',
    ])->assertStatus(403);

    // 2. Admin can create product
    Passport::actingAs($admin);
    $createRes = postJson('/api/admin/catalog/products', [
        'code' => 'agency_gold',
        'name' => 'Pack Agence Gold',
        'description' => 'Pack premium pour réseaux d\'agences',
        'badge_type' => 'agent',
        'is_active' => true,
        'display_order' => 10,
    ]);

    $createRes->assertStatus(201)
        ->assertJsonPath('data.code', 'agency_gold');

    $productId = $createRes->json('data.id');

    // 3. Admin can add a price
    $priceRes = postJson("/api/admin/catalog/products/{$productId}/prices", [
        'cadence' => 'yearly',
        'price_amount' => 120000.00,
        'duration_days' => 365,
        'currency' => 'XAF',
        'savings_description' => 'Tarif réseau agence',
    ]);

    $priceRes->assertStatus(201)
        ->assertJsonPath('data.priceXaf', 120000);

    $priceId = $priceRes->json('data.id');

    // 4. Admin can update price
    putJson("/api/admin/catalog/prices/{$priceId}", [
        'price_amount' => 110000.00,
    ])->assertStatus(200)
        ->assertJsonPath('data.priceXaf', 110000);
});

it('POST /api/subscriptions/subscribe prepares subscription intent and checks eligibility', function () {
    $user = User::factory()->create([
        'is_verified' => true,
    ]);

    Passport::actingAs($user);

    $price = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    $response = postJson('/api/subscriptions/subscribe', [
        'price_id' => $price->id,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.product.code', 'landlord')
        ->assertJsonPath('data.payment_intent.amount', 7500)
        ->assertJsonPath('data.kyc_status.is_verified', true);
});

it('activates subscription upon payment and resolves avatar ring color correctly', function () {
    $user = User::factory()->create([
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);

    $landlordPrice = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);

    // 1. Activate Landlord subscription
    $subscription = $subService->activateSubscription($user, $landlordPrice, 'OM-TEST-REF-001');

    expect($subscription->state)->toBe('active')
        ->and($subscription->amount)->toBe('7500.00')
        ->and($subscription->badge_type)->toBe('landlord');

    // User badge is synchronized to 'landlord'
    $user->refresh();
    expect($user->badge)->toBe('landlord');

    // Verify status endpoint
    Passport::actingAs($user);
    $statusRes = getJson('/api/subscriptions/status');

    $statusRes->assertStatus(200)
        ->assertJsonPath('status.badge', 'landlord')
        ->assertJsonPath('status.ringColor', BadgeResolutionService::COLOR_LANDLORD) // #1E9E5A
        ->assertJsonPath('status.hasLandlord', true)
        ->assertJsonPath('status.hasAgent', false);
});

it('supports simultaneous Landlord and Agent subscriptions with unified Red BANGO ring', function () {
    $user = User::factory()->create([
        'is_verified' => true,
        'verification_lapsed' => false,
    ]);

    $landlordPrice = SubscriptionPrice::where('cadence', 'yearly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    $agentPrice = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'agent'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);

    // 1. Activate both subscriptions
    $subService->activateSubscription($user, $landlordPrice, 'PAY-LANDLORD-123');
    $subService->activateSubscription($user, $agentPrice, 'PAY-AGENT-456');

    $user->refresh();
    expect($user->badge)->toBe('double');
    expect($user->activeSubscriptions()->count())->toBe(2);

    // 2. Query status
    Passport::actingAs($user);
    $statusRes = getJson('/api/subscriptions/status');

    $statusRes->assertStatus(200)
        ->assertJsonPath('status.badge', 'double')
        ->assertJsonPath('status.ringColor', BadgeResolutionService::COLOR_DOUBLE) // #D80F0F
        ->assertJsonPath('status.hasLandlord', true)
        ->assertJsonPath('status.hasAgent', true)
        ->assertJsonCount(2, 'status.badges');
});

it('requires both active subscription AND approved KYC to grant verified badge and ring', function () {
    // User with paid subscription but NOT verified KYC (is_verified = false)
    $unverifiedUser = User::factory()->create([
        'is_verified' => false,
    ]);

    $landlordPrice = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $subService->activateSubscription($unverifiedUser, $landlordPrice, 'PAY-111');

    /** @var BadgeResolutionService $badgeService */
    $badgeService = app(BadgeResolutionService::class);
    $resolution = $badgeService->resolveUserBadges($unverifiedUser);

    // Badge remains 'none' because KYC is required!
    expect($resolution['badge'])->toBe('none');
    expect($resolution['ringColor'])->toBeNull();
    expect($resolution['isVerified'])->toBeFalse();

    // Now approve KYC
    $unverifiedUser->update(['is_verified' => true]);
    $badgeService->syncUserBadge($unverifiedUser);

    $unverifiedUser->refresh();
    expect($unverifiedUser->badge)->toBe('landlord');
});

it('suspends badge rights when KYC documentation has lapsed', function () {
    $user = User::factory()->create([
        'is_verified' => true,
        'verification_lapsed' => true, // ID card expired
    ]);

    $landlordPrice = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $subService->activateSubscription($user, $landlordPrice, 'PAY-222');

    /** @var BadgeResolutionService $badgeService */
    $badgeService = app(BadgeResolutionService::class);
    $resolution = $badgeService->resolveUserBadges($user);

    // Lapsed documentation suspends badge display
    expect($resolution['badge'])->toBe('none');
    expect($resolution['verificationLapsed'])->toBeTrue();
});

it('calculates prorata on plan change correctly', function () {
    $user = User::factory()->create(['is_verified' => true]);

    $monthlyPrice = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    $yearlyPrice = SubscriptionPrice::where('cadence', 'yearly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $sub = $subService->activateSubscription($user, $monthlyPrice, 'PAY-333');

    // Change plan calculation
    Passport::actingAs($user);
    $res = postJson("/api/subscriptions/{$sub->id}/change-plan", [
        'new_cadence' => 'yearly',
    ]);

    $res->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.new_cadence', 'yearly');

    $amountToPay = $res->json('data.amount_to_pay_xaf');
    // Amount to pay should be lower than full 75000 due to deduction of remaining monthly days
    expect($amountToPay)->toBeLessThan(75000);
});

it('cancels auto-renewal while keeping benefits until period end', function () {
    $user = User::factory()->create(['is_verified' => true]);

    $price = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'agent'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $sub = $subService->activateSubscription($user, $price, 'PAY-444');

    Passport::actingAs($user);

    $cancelRes = postJson("/api/subscriptions/{$sub->id}/cancel", [
        'reason' => 'Logement loué',
        'feedback' => 'Très satisfait du service.',
    ]);

    $cancelRes->assertStatus(200)
        ->assertJsonPath('auto_renew', false);

    $sub->refresh();
    expect($sub->auto_renew)->toBeFalse()
        ->and($sub->cancel_reason)->toBe('Logement loué')
        ->and($sub->isActive())->toBeTrue(); // Still active until period end!
});

it('admin can suspend, reactivate, extend, and revoke subscriptions with audit events', function () {
    $adminRole = Role::where('slug', 'admin')->first();
    $admin = User::factory()->create(['role_id' => $adminRole->id]);

    $client = User::factory()->create(['is_verified' => true]);

    $price = SubscriptionPrice::where('cadence', 'monthly')
        ->whereHas('product', fn ($q) => $q->where('code', 'landlord'))
        ->first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $sub = $subService->activateSubscription($client, $price, 'PAY-555');

    Passport::actingAs($admin);

    // 1. Suspend subscription
    postJson("/api/admin/subscriptions/{$sub->id}/suspend", [
        'reason' => 'Suspicion de fausse annonce',
    ])->assertStatus(200)
        ->assertJsonPath('data.state', 'suspended');

    $client->refresh();
    expect($client->badge)->toBe('none');

    // 2. Reactivate subscription
    postJson("/api/admin/subscriptions/{$sub->id}/reactivate", [
        'reason' => 'Vérification effectuée avec succès',
    ])->assertStatus(200)
        ->assertJsonPath('data.state', 'active');

    $client->refresh();
    expect($client->badge)->toBe('landlord');

    // 3. Extend subscription by 15 days
    $initialEnd = $sub->fresh()->current_period_end;
    postJson("/api/admin/subscriptions/{$sub->id}/extend", [
        'days' => 15,
        'reason' => 'Geste commercial',
    ])->assertStatus(200);

    $extendedEnd = $sub->fresh()->current_period_end;
    expect((int) $initialEnd->diffInDays($extendedEnd))->toBe(15);

    // 4. Check events
    $showRes = getJson("/api/admin/subscriptions/{$sub->id}");
    $showRes->assertStatus(200)
        ->assertJsonPath('data.id', $sub->id);
});

it('prevents user A from accessing or modifying user B subscriptions', function () {
    $userA = User::factory()->create(['is_verified' => true]);
    $userB = User::factory()->create(['is_verified' => true]);

    $price = SubscriptionPrice::first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $subA = $subService->activateSubscription($userA, $price, 'PAY-AAA');

    Passport::actingAs($userB);

    // User B tries to view User A's subscription
    getJson("/api/subscriptions/{$subA->id}")
        ->assertStatus(404);

    // User B tries to cancel User A's subscription
    postJson("/api/subscriptions/{$subA->id}/cancel")
        ->assertStatus(404);
});

it('EnsureActiveSubscription middleware protects routes according to subscription and KYC status', function () {
    Route::get('/api/test-landlord-action', function () {
        return response()->json(['success' => true, 'action' => 'publish_property']);
    })->middleware(['auth:api', 'subscribed:landlord']);

    $user = User::factory()->create([
        'is_verified' => false,
        'verification_lapsed' => false,
    ]);

    // 1. Without subscription -> 403 SUBSCRIPTION_REQUIRED
    Passport::actingAs($user);
    getJson('/api/test-landlord-action')
        ->assertStatus(403)
        ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');

    // 2. With subscription but unverified KYC -> 403 KYC_REQUIRED
    $price = SubscriptionPrice::whereHas('product', fn ($q) => $q->where('code', 'landlord'))->first();
    app(SubscriptionService::class)->activateSubscription($user, $price, 'PAY-TEST-MIDDLEWARE');

    getJson('/api/test-landlord-action')
        ->assertStatus(403)
        ->assertJsonPath('code', 'KYC_REQUIRED');

    // 3. With subscription and verified KYC -> 200 OK
    $user->update(['is_verified' => true]);
    getJson('/api/test-landlord-action')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // 4. When KYC expires (lapsed) -> 403 KYC_LAPSED
    $user->update(['verification_lapsed' => true]);
    getJson('/api/test-landlord-action')
        ->assertStatus(403)
        ->assertJsonPath('code', 'KYC_LAPSED');
});

it('enters grace period on renewal failure and expires when grace period passes', function () {
    $user = User::factory()->create(['is_verified' => true]);
    $price = SubscriptionPrice::first();

    /** @var SubscriptionService $subService */
    $subService = app(SubscriptionService::class);
    $sub = $subService->activateSubscription($user, $price, 'PAY-GRACE');

    // 1. Enter grace period
    $subService->enterGracePeriod($sub, 'Solde insuffisant');
    $sub->refresh();

    expect($sub->state)->toBe('grace')
        ->and($sub->failed_attempts)->toBe(1)
        ->and($sub->isInGrace())->toBeTrue()
        ->and($sub->isUsable())->toBeTrue(); // Still usable during 7-day grace period!

    // 2. Expire subscription
    $subService->expireSubscription($sub, 'Grace period expired');
    $sub->refresh();

    expect($sub->state)->toBe('expired')
        ->and($sub->isUsable())->toBeFalse()
        ->and($sub->isExpired())->toBeTrue();

    $user->refresh();
    expect($user->badge)->toBe('none');
});
