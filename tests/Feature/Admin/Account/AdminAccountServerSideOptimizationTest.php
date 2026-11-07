<?php

namespace Tests\Feature\Admin\Account;

use App\Enums\Listing\ListingStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
use App\Models\Square\Post;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.test.'.uniqid().'@bango.app',
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

test('accounts search is case-insensitive and finds uppercase and lowercase variants', function () {
    User::factory()->create([
        'name' => 'Marie Dupont',
        'display_name' => 'Nono Marie',
        'handle' => 'nonomarie515',
        'email' => 'marie.dupont@example.com',
    ]);

    // Recherche en minuscules
    $resLower = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/accounts?q=marie');

    $resLower->assertOk();
    expect($resLower->json('total'))->toBeGreaterThanOrEqual(1);

    // Recherche en majuscules
    $resUpper = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/accounts?q=MARIE');

    $resUpper->assertOk();
    expect($resUpper->json('total'))->toBeGreaterThanOrEqual(1);
});

test('accounts filter supports array of statuses and ignores invalid status', function () {
    $u1 = User::factory()->create(['status' => 'suspended']);
    $u2 = User::factory()->create(['status' => 'blocked']);
    $u3 = User::factory()->create(['status' => 'active']);

    // status[]=suspended&status[]=blocked
    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/accounts?status[]=suspended&status[]=blocked');

    $res->assertOk();
    $ids = collect($res->json('data'))->pluck('id');
    expect($ids)->toContain($u1->id)
        ->and($ids)->toContain($u2->id)
        ->and($ids)->not->toContain($u3->id);

    // Statut invalide type nimportequoi ignoré
    $resInvalid = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/accounts?status=nimportequoi');

    $resInvalid->assertOk();
});

test('accounts filter supports city and date range', function () {
    $currency = Currency::first() ?? Currency::create(['name' => 'FCFA', 'code' => 'XAF', 'symbol' => 'FCFA']);
    $country = Country::first() ?? Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'phone_code' => '237', 'currency_id' => $currency->id]);
    $city = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $country->id]);

    $uDouala = User::factory()->create(['city_id' => $city->id]);
    $uOther = User::factory()->create(['city_id' => null]);

    $resCity = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/accounts?city_id={$city->id}");

    $resCity->assertOk();
    $ids = collect($resCity->json('data'))->pluck('id');
    expect($ids)->toContain($uDouala->id)
        ->and($ids)->not->toContain($uOther->id);
});

test('accounts deletions route lists soft deleted and scheduled for deletion accounts', function () {
    $deletedUser = User::factory()->create(['deleted_at' => now()]);
    $scheduledUser = User::factory()->create(['status' => 'scheduled_for_deletion']);
    $activeUser = User::factory()->create(['status' => 'active']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/accounts/deletions');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($deletedUser->id)
        ->and($ids)->toContain($scheduledUser->id)
        ->and($ids)->not->toContain($activeUser->id);
});

test('account details endpoint exposes 360 cards metrics and views', function () {
    $targetUser = User::factory()->create();

    // Publication avec 150 vues
    Post::factory()->create([
        'user_id' => $targetUser->id,
        'views_count' => 150,
    ]);

    // Annonce louée
    Listing::factory()->create([
        'owner_id' => $targetUser->id,
        'status' => ListingStatus::Taken->value,
        'views_count' => 50,
    ]);

    // Transaction de paiement
    PaymentTransaction::create([
        'reference' => 'TX-TEST-001',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $targetUser->id,
        'amount' => 15000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'subscription',
        'provider' => 'monetbil',
        'created_at' => now()->subMonths(2),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/accounts/{$targetUser->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('account.views_count', 200)
        ->assertJsonPath('account.rented_listings_count', 1)
        ->assertJsonPath('account.total_paid_12m', 15000);
});

test('user payments endpoint accepts from period filter', function () {
    $targetUser = User::factory()->create();

    // Paiement ancien (> 1 an)
    $oldTx = PaymentTransaction::create([
        'reference' => 'TX-OLD-01',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $targetUser->id,
        'amount' => 5000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);
    $oldTx->created_at = now()->subMonths(18);
    $oldTx->saveQuietly();

    // Paiement récent (< 3 mois)
    $recentTx = PaymentTransaction::create([
        'reference' => 'TX-RECENT-01',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $targetUser->id,
        'amount' => 10000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);
    $recentTx->created_at = now()->subMonths(2);
    $recentTx->saveQuietly();

    $fromDate = now()->subMonths(6)->toDateString();

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/accounts/{$targetUser->id}/payments?from={$fromDate}");

    $response->assertOk();
    $refs = collect($response->json('data'))->pluck('reference');
    expect($refs)->toContain('TX-RECENT-01')
        ->and($refs)->not->toContain('TX-OLD-01');
});

test('timeline endpoint is paginated', function () {
    $targetUser = User::factory()->create();

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/accounts/{$targetUser->id}/timeline?per_page=10&page=1");

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data',
            'pagination' => ['current_page', 'per_page', 'total', 'last_page'],
            'page',
            'perPage',
            'total',
        ]);
});

test('shell counters includes overdue metrics for moderation kyc and support', function () {
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/shell/counters');

    $response->assertOk()
        ->assertJsonStructure([
            'counters' => [
                'moderation_overdue',
                'kyc_overdue',
                'support_overdue',
                'overdue_total',
            ],
        ]);
});
