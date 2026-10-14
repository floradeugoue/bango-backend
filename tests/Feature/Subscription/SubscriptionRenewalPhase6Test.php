<?php

use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Auth\Role;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Notifications\Subscription\PaymentFailedReminderNotification;
use App\Notifications\Subscription\RenewalUpcomingReminderNotification;
use App\Services\Payment\Gateways\MonetbilGateway;
use App\Services\Subscription\SubscriptionRenewalService;
use App\Services\Subscription\SubscriptionService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

use function Pest\Laravel\artisan;
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
        'payment.gateways.monetbil.service_key' => 'mock_secret_key_123',
        'payment.gateways.monetbil.service_secret' => 'super_secret_monetbil_456',
        'payment.gateways.monetbil.api_base_url' => 'https://api.monetbil.com',
        'payment.timeout_seconds' => 120,
        'subscription.reminders_days_before' => [7, 3, 1],
        'subscription.grace_period_days' => 7,
        'subscription.retry_delays_hours' => [24, 72],
    ]);

    $this->userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);

    $this->userA = User::factory()->create([
        'role_id' => $this->userRole->id,
        'is_verified' => true,
    ]);
    $this->userB = User::factory()->create([
        'role_id' => $this->userRole->id,
        'is_verified' => true,
    ]);

    $this->product = SubscriptionProduct::where('code', 'landlord')->firstOrFail();
    $this->price = $this->product->activePrices()->where('cadence', 'monthly')->firstOrFail();
});

it('1. sends renewal upcoming reminders at J-7, J-3, and J-1 with correct details', function () {
    Notification::fake();

    // Create a subscription expiring in exactly 7 days
    $sub7d = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => $this->product->badge_type ?? 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(23),
        'current_period_end' => now()->addDays(7)->setTime(12, 0, 0),
        'auto_renew' => true,
    ]);

    $service = app(SubscriptionRenewalService::class);
    $stats = $service->sendUpcomingReminders();

    expect($stats['sent'])->toBe(1);

    Notification::assertSentTo(
        $this->userA,
        RenewalUpcomingReminderNotification::class,
        function ($notification) use ($sub7d) {
            return $notification->subscription->id === $sub7d->id
                && $notification->daysRemaining === 7
                && $notification->reminderType === 'upcoming_7d';
        }
    );

    // Verify reminder is logged in subscription_reminders table
    $this->assertDatabaseHas('subscription_reminders', [
        'subscription_id' => $sub7d->id,
        'reminder_type' => 'upcoming_7d',
    ]);
});

it('2. guarantees idempotence and avoids duplicate reminders upon repeated runs', function () {
    Notification::fake();

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => $this->product->badge_type ?? 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(27),
        'current_period_end' => now()->addDays(3)->setTime(14, 0, 0),
        'auto_renew' => true,
    ]);

    $service = app(SubscriptionRenewalService::class);

    // First run
    $stats1 = $service->sendUpcomingReminders();
    expect($stats1['sent'])->toBe(1);
    expect($stats1['skipped'])->toBe(0);

    // Second run immediately afterwards
    $stats2 = $service->sendUpcomingReminders();
    expect($stats2['sent'])->toBe(0);
    expect($stats2['skipped'])->toBe(1);

    // Only 1 notification should have been sent to user
    Notification::assertSentTimes(RenewalUpcomingReminderNotification::class, 1);
});

it('3. does not send reminders for already renewed or expired subscriptions', function () {
    Notification::fake();

    // 1. Expired subscription
    $expiredSub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'expired',
        'current_period_start' => now()->subDays(40),
        'current_period_end' => now()->subDays(10),
        'auto_renew' => false,
    ]);

    // 2. Already renewed subscription (expiring in 35 days)
    $renewedSub = Subscription::create([
        'user_id' => $this->userB->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(5),
        'current_period_end' => now()->addDays(35),
        'auto_renew' => true,
    ]);

    $service = app(SubscriptionRenewalService::class);
    $stats = $service->sendUpcomingReminders();

    expect($stats['sent'])->toBe(0);
    Notification::assertNothingSent();
});

it('4. sends retry reminder 24h after definitive payment failure', function () {
    Notification::fake();

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'grace',
        'current_period_start' => now()->subDays(31),
        'current_period_end' => now()->subDay(),
        'grace_until' => now()->addDays(6),
        'auto_renew' => true,
    ]);

    // Create a failed transaction 25 hours ago
    $failedTx = PaymentTransaction::factory()->create([
        'user_id' => $this->userA->id,
        'subscription_id' => $sub->id,
        'service_type' => PaymentServiceType::Subscription,
        'status' => PaymentStatus::Failed,
        'failed_at' => now()->subHours(25),
        'total_amount' => 7500,
    ]);

    $service = app(SubscriptionRenewalService::class);
    $stats = $service->sendFailedPaymentRetries();

    expect($stats['sent'])->toBe(1);

    Notification::assertSentTo(
        $this->userA,
        PaymentFailedReminderNotification::class,
        function ($notification) use ($sub, $failedTx) {
            return $notification->subscription->id === $sub->id
                && $notification->failedTransaction->id === $failedTx->id
                && $notification->reminderType === 'failed_payment_24h';
        }
    );
});

it('5. never treats a pending or processing payment as a failure during retries', function () {
    Notification::fake();

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(30),
        'current_period_end' => now()->addDays(2),
        'auto_renew' => true,
    ]);

    // Transaction that is STILL in processing (pending user USSD validation)
    PaymentTransaction::factory()->create([
        'user_id' => $this->userA->id,
        'subscription_id' => $sub->id,
        'service_type' => PaymentServiceType::Subscription,
        'status' => PaymentStatus::Processing,
        'created_at' => now()->subMinutes(10),
    ]);

    $service = app(SubscriptionRenewalService::class);
    $stats = $service->sendFailedPaymentRetries();

    expect($stats['sent'])->toBe(0);
    Notification::assertNothingSent();
});

it('6. does not send failure retries for undetermined status', function () {
    Notification::fake();

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(30),
        'current_period_end' => now()->addDays(2),
        'auto_renew' => true,
    ]);

    // Transaction with unknown/undetermined status (timeout / under investigation)
    PaymentTransaction::factory()->create([
        'user_id' => $this->userA->id,
        'subscription_id' => $sub->id,
        'service_type' => PaymentServiceType::Subscription,
        'status' => PaymentStatus::Unknown,
        'created_at' => now()->subHours(25),
    ]);

    $service = app(SubscriptionRenewalService::class);
    $stats = $service->sendFailedPaymentRetries();

    expect($stats['sent'])->toBe(0);
    Notification::assertNothingSent();
});

it('7. confirms that no payment or debit is ever triggered automatically by scheduler', function () {
    Notification::fake();

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(29),
        'current_period_end' => now()->addDay(),
        'auto_renew' => true,
    ]);

    $initialTxCount = PaymentTransaction::count();

    // Run all scheduler commands
    artisan('subscription:send-renewal-reminders')->assertExitCode(0);
    artisan('subscription:send-failed-payment-reminders')->assertExitCode(0);
    artisan('subscription:process-lifecycle')->assertExitCode(0);

    // Number of financial transactions MUST remain strictly unchanged
    expect(PaymentTransaction::count())->toBe($initialTxCount);
});

it('8. validates price and quote strictly server-side for renewal quote endpoint', function () {
    Passport::actingAs($this->userA, ['*']);

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(25),
        'current_period_end' => now()->addDays(5),
        'auto_renew' => true,
    ]);

    // Send arbitrary client price to attempt manipulation
    $response = postJson("/api/subscriptions/{$sub->id}/renewal/quote", [
        'cadence' => 'monthly',
        'fake_amount' => 10,
    ])->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'subscription_id',
                'product',
                'price',
                'pricing' => [
                    'base_amount',
                    'fee_amount',
                    'tax_amount',
                    'total_amount',
                    'currency',
                    'gateway',
                ],
                'requires_manual_confirmation',
            ],
        ]);

    // The price returned must strictly be the server-side price (7500 XAF)
    expect($response->json('data.pricing.base_amount'))->toEqual(7500);
    expect($response->json('data.requires_manual_confirmation'))->toBeTrue();
});

it('9. prevents concurrent or duplicate renewal initiation when push is already processing', function () {
    Passport::actingAs($this->userA, ['*']);

    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(29),
        'current_period_end' => now()->addDay(),
        'auto_renew' => true,
    ]);

    Http::fake([
        'https://api.monetbil.com/payment/v1/placePayment' => Http::response([
            'status' => 'REQUEST_ACCEPTED',
            'paymentId' => 'mb_renewal_mock_999',
            'channel_ussd' => '#150*50#',
        ], 200),
    ]);

    // First confirmation -> initiates Monetbil payment
    $res1 = postJson("/api/subscriptions/{$sub->id}/renewal/confirm", [
        'operator' => 'MTN',
        'phone' => '650000001',
    ])->assertStatus(201);

    $tx1Id = $res1->json('data.id');

    // Second immediate confirmation -> detects pending transaction and returns it without creating a new one
    $res2 = postJson("/api/subscriptions/{$sub->id}/renewal/confirm", [
        'operator' => 'MTN',
        'phone' => '650000001',
    ])->assertStatus(200);

    expect($res2->json('data.id'))->toBe($tx1Id);
    expect(PaymentTransaction::where('subscription_id', $sub->id)->count())->toBe(1);
});

it('10. respects SUB grace period of 7 days before expring', function () {
    // Create subscription that just expired 1 hour ago
    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(31),
        'current_period_end' => now()->subHour(),
        'auto_renew' => true,
    ]);

    $service = app(SubscriptionRenewalService::class);
    $stats = $service->processLifecycle();

    expect($stats['entered_grace'])->toBe(1);
    expect($stats['expired'])->toBe(0);

    $sub->refresh();
    expect($sub->state)->toBe('grace');
    expect($sub->isInGrace())->toBeTrue();
    expect($sub->isUsable())->toBeTrue();
    expect($sub->grace_until)->not->toBeNull();
});

it('11. expires subscriptions idempotently after grace period ends and revokes badge', function () {
    // Create subscription whose grace period ended yesterday
    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'grace',
        'current_period_start' => now()->subDays(40),
        'current_period_end' => now()->subDays(9),
        'grace_until' => now()->subDay(),
        'auto_renew' => true,
    ]);

    $service = app(SubscriptionRenewalService::class);

    // First lifecycle run
    $stats1 = $service->processLifecycle();
    expect($stats1['expired'])->toBe(1);

    $sub->refresh();
    expect($sub->state)->toBe('expired');
    expect($sub->isExpired())->toBeTrue();
    expect($sub->isUsable())->toBeFalse();

    // Second lifecycle run is idempotent
    $stats2 = $service->processLifecycle();
    expect($stats2['expired'])->toBe(0);
});

it('12. ensures non-regression on badge sync upon confirmed renewal payment', function () {
    $sub = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(20),
        'current_period_end' => now()->addDays(10),
        'auto_renew' => true,
    ]);

    $oldEnd = $sub->current_period_end->copy();

    // Simulate renewal by confirming a renewal transaction
    $subService = app(SubscriptionService::class);
    $renewed = $subService->activateSubscription(
        user: $this->userA,
        price: $this->price,
        paymentReference: 'BNG-TX-RENEWAL-MOCK-1',
        metadata: ['context' => 'manual_renewal']
    );

    $renewed->refresh();
    expect($renewed->state)->toBe('active');
    // Period end extended by 30 days from previous end
    expect((int) $oldEnd->diffInDays($renewed->current_period_end))->toBe(30);

    // User badge remains synced
    $this->userA->refresh();
    expect($this->userA->badge)->toBe('landlord');
});

it('13. enforces strict data isolation between different users', function () {
    // User A owns subA
    $subA = Subscription::create([
        'user_id' => $this->userA->id,
        'product_id' => $this->product->id,
        'price_id' => $this->price->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => $this->price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDays(20),
        'current_period_end' => now()->addDays(10),
        'auto_renew' => true,
    ]);

    // User B tries to access User A's subscription renewal info, quote or confirm
    Passport::actingAs($this->userB, ['*']);

    getJson("/api/subscriptions/{$subA->id}/renewal")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SUBSCRIPTION_NOT_FOUND');

    postJson("/api/subscriptions/{$subA->id}/renewal/quote", [])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'SUBSCRIPTION_NOT_FOUND');

    postJson("/api/subscriptions/{$subA->id}/renewal/confirm", [
        'operator' => 'MTN',
        'phone' => '650000002',
    ])->assertStatus(404)
        ->assertJsonPath('error.code', 'SUBSCRIPTION_NOT_FOUND');
});

it('14. maintains backward compatibility with Passport and previous PAY phases', function () {
    Passport::actingAs($this->userA, ['*']);

    // Payments mobile catalog is still accessible
    getJson('/api/payments/catalog')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    // Subscriptions list is still accessible
    getJson('/api/subscriptions')
        ->assertStatus(200)
        ->assertJsonStructure(['data']);
});
