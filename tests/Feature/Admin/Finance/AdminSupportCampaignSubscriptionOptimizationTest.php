<?php

namespace Tests\Feature\Admin\Finance;

use App\Enums\Boost\CampaignObjective;
use App\Enums\Boost\CampaignStatus;
use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Square\Post;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.support.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->sessionId,
        'user_id' => $this->operator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

test('admin support tickets supports multi-status, search q, tab counts, and overdue filter', function () {
    $u1 = User::factory()->create([
        'name' => 'Fatou Fall',
        'display_name' => 'Fatou Dakar',
        'email' => 'fatou@bango.app',
    ]);
    $u2 = User::factory()->create([
        'name' => 'Koffi Kouassi',
        'display_name' => 'Koffi Abidjan',
        'email' => 'koffi@bango.app',
    ]);

    $t1 = SupportTicket::create([
        'user_id' => $u1->id,
        'reference' => 'TCK-FATOU-001',
        'subject' => 'Problème de validation badge',
        'category' => TicketCategory::SubscriptionBadge,
        'priority' => TicketPriority::High,
        'status' => TicketStatus::Open,
        'created_at' => now()->subHours(30),
    ]);
    $t1->timestamps = false;
    $t1->created_at = now()->subHours(30);
    $t1->save();

    $t2 = SupportTicket::create([
        'user_id' => $u2->id,
        'reference' => 'TCK-KOFFI-002',
        'subject' => 'Question facturation',
        'category' => TicketCategory::PaymentWallet,
        'priority' => TicketPriority::Normal,
        'status' => TicketStatus::Resolved,
        'resolved_at' => now(),
    ]);

    // 1. Search q matching user display_name
    $resSearch = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/support/tickets?q=Fatou+Dakar');

    $resSearch->assertOk();
    $itemsSearch = $resSearch->json('items') ?? $resSearch->json('data');
    expect($itemsSearch)->toHaveCount(1)
        ->and($itemsSearch[0]['reference'])->toBe('TCK-FATOU-001');

    // 2. Multi-status and tab counts
    $resMulti = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/support/tickets?status[]=open&status[]=resolved');

    $resMulti->assertOk();
    expect($resMulti->json('meta.counts.open'))->toBeGreaterThanOrEqual(1)
        ->and($resMulti->json('meta.counts.resolved'))->toBeGreaterThanOrEqual(1);

    // 3. Overdue SLA filter (> 24h without reply)
    $resOverdue = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/support/tickets?overdue=1');

    $resOverdue->assertOk();
    $overdueItems = $resOverdue->json('items') ?? $resOverdue->json('data');
    expect($overdueItems)->toHaveCount(1)
        ->and($overdueItems[0]['reference'])->toBe('TCK-FATOU-001');
});

test('admin campaigns validates min 2000 FCFA budget, auto-closes expired campaigns, and aggregates totals', function () {
    $advertiser = User::factory()->create();

    // 1. Minimum budget validation (2000 FCFA)
    Passport::actingAs($advertiser);
    $lowBudgetResp = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => 'dummy-id',
        'budget' => 1500, // < 2000
        'duration_days' => 5,
    ]);
    $lowBudgetResp->assertStatus(422)
        ->assertJsonValidationErrors(['budget']);

    // 2. Auto-close overdue campaign
    $post = Post::factory()->create(['user_id' => $advertiser->id]);

    $expiredCamp = Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::Reach,
        'budget_amount' => 5000,
        'spent_amount' => 2000,
        'duration_days' => 5,
        'status' => CampaignStatus::Active,
        'start_at' => now()->subDays(6),
        'end_at' => now()->subDay(),
    ]);

    $activeCamp = Campaign::create([
        'user_id' => $advertiser->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::Engagement,
        'budget_amount' => 10000,
        'spent_amount' => 1500,
        'duration_days' => 7,
        'status' => CampaignStatus::Active,
        'start_at' => now(),
        'end_at' => now()->addDays(7),
    ]);

    // Admin campaign list triggers auto-close
    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/campaigns');

    $res->assertOk();
    $expiredCamp->refresh();
    expect($expiredCamp->status)->toBe(CampaignStatus::Completed)
        ->and($res->json('meta.totals.total_budget'))->toBeGreaterThanOrEqual(15000)
        ->and($res->json('meta.counts.active'))->toBeGreaterThanOrEqual(1)
        ->and($res->json('meta.counts.completed'))->toBeGreaterThanOrEqual(1);
});

test('admin subscriptions auto-closes subscriptions of deleted users, supports multi-state, cadence, and computes MRR', function () {
    $activeUser = User::factory()->create();
    $deletedUser = User::factory()->create(['deleted_at' => now()]);

    $product = SubscriptionProduct::firstOrCreate(
        ['code' => 'badge_landlord'],
        ['name' => 'Badge Bailleur', 'type' => 'badge', 'display_order' => 1]
    );

    $monthlyPrice = SubscriptionPrice::firstOrCreate(
        ['product_id' => $product->id, 'cadence' => 'monthly'],
        ['amount' => 5000, 'price_amount' => 5000, 'duration_days' => 30, 'currency' => 'XAF', 'is_active' => true]
    );

    $yearlyPrice = SubscriptionPrice::firstOrCreate(
        ['product_id' => $product->id, 'cadence' => 'yearly'],
        ['amount' => 60000, 'price_amount' => 60000, 'duration_days' => 365, 'currency' => 'XAF', 'is_active' => true]
    );

    // Active subscription for active user (Monthly: 5000)
    $subActive = Subscription::create([
        'user_id' => $activeUser->id,
        'product_id' => $product->id,
        'price_id' => $monthlyPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'monthly',
        'amount' => 5000,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
    ]);

    // Active subscription for deleted user (should be auto-closed)
    $subDeleted = Subscription::create([
        'user_id' => $deletedUser->id,
        'product_id' => $product->id,
        'price_id' => $yearlyPrice->id,
        'badge_type' => 'landlord',
        'cadence' => 'yearly',
        'amount' => 60000,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now(),
        'current_period_end' => now()->addYear(),
    ]);

    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/subscriptions');

    $res->assertOk();
    $subDeleted->refresh();
    expect($subDeleted->state)->toBe('cancelled')
        ->and($res->json('meta.mrr'))->toEqual(5000)
        ->and($res->json('meta.counts.active'))->toBe(1)
        ->and($res->json('meta.counts.cancelled'))->toBeGreaterThanOrEqual(1);

    // Cadence filter
    $resCadence = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/subscriptions?cadence=monthly');

    $resCadence->assertOk();
    expect($resCadence->json('data'))->toHaveCount(1);
});

test('admin moderation stats returns appeal and overturn rates', function () {
    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/stats/moderation');

    $res->assertOk();
    expect($res->json('data'))->toHaveKeys([
        'reports_total',
        'reports_resolved',
        'appeals_total',
        'appeal_rate_percentage',
        'overturn_rate_percentage',
    ]);
});

test('status counter queries do not include order by or non-grouped columns in group by', function () {
    $executedQueries = [];
    DB::listen(function ($query) use (&$executedQueries) {
        $executedQueries[] = $query->sql;
    });

    // 1. Campaigns
    $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/campaigns')
        ->assertOk();

    // 2. Support tickets
    $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/support/tickets')
        ->assertOk();

    // 3. Subscriptions
    $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/subscriptions')
        ->assertOk();

    $groupByQueries = array_filter($executedQueries, function (string $sql) {
        $lower = strtolower($sql);

        return str_contains($lower, 'group by') && (
            str_contains($lower, 'status') || str_contains($lower, 'state')
        );
    });

    expect($groupByQueries)->not->toBeEmpty();

    foreach ($groupByQueries as $sql) {
        $lower = strtolower($sql);
        // Ensure no ORDER BY exists on groupBy count queries
        expect($lower)->not->toContain('order by');

        // On support_tickets count queries, ensure support_tickets.id / messages_count subqueries are not present
        if (str_contains($lower, 'support_tickets')) {
            expect($lower)->not->toContain('messages_count');
            expect($lower)->not->toContain('support_ticket_messages');
        }
    }
});
