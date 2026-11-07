<?php

namespace Tests\Feature\Admin\Payment;

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Boost\CreditTransaction;
use App\Models\Boost\CreditWallet;
use App\Models\Payment\PaymentAttempt;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.pay.'.uniqid().'@bango.app',
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

test('transactions search q matches reference, user handle, display_name and last 3 phone digits', function () {
    $u1 = User::factory()->create([
        'name' => 'Jean Paul',
        'display_name' => 'JP Douala',
        'handle' => 'jpdouala',
        'phone' => '+237699112233',
    ]);
    $u2 = User::factory()->create([
        'name' => 'Alice Martin',
        'display_name' => 'Alice M',
        'handle' => 'alicem',
        'phone' => '+237677445566',
    ]);

    PaymentTransaction::create([
        'reference' => 'TX-JP-001',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $u1->id,
        'amount' => 5000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    PaymentTransaction::create([
        'reference' => 'TX-ALICE-002',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $u2->id,
        'amount' => 10000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'subscription',
        'provider' => 'monetbil',
    ]);

    // Recherche par derniers chiffres du numéro de JP (233)
    $resPhone = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/transactions?q=233');

    $resPhone->assertOk();
    $refs = collect($resPhone->json('data'))->pluck('reference');
    expect($refs)->toContain('TX-JP-001')
        ->and($refs)->not->toContain('TX-ALICE-002');

    // Recherche par display_name
    $resName = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/transactions?q=Alice+M');
    $resName->assertOk();
    $refsAlice = collect($resName->json('data'))->pluck('reference');
    expect($refsAlice)->toContain('TX-ALICE-002')
        ->and($refsAlice)->not->toContain('TX-JP-001');
});

test('transactions endpoint exposes meta counts for tab counters and supports multi-status filter', function () {
    $user = User::factory()->create();

    PaymentTransaction::create([
        'reference' => 'TX-CONFIRMED',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 5000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    PaymentTransaction::create([
        'reference' => 'TX-FAILED',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 2000,
        'currency' => 'XAF',
        'status' => 'failed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    PaymentTransaction::create([
        'reference' => 'TX-PENDING',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 3000,
        'currency' => 'XAF',
        'status' => 'pending',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    // Filtrer par status=confirmed tout en vérifiant les meta.counts globaux
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/transactions?status=confirmed');

    $response->assertOk();
    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['reference'])->toBe('TX-CONFIRMED');

    $metaCounts = $response->json('meta.counts');
    expect($metaCounts)->not->toBeNull()
        ->and($metaCounts['all'])->toBe(3)
        ->and($metaCounts['confirmed'])->toBe(1)
        ->and($metaCounts['failed'])->toBe(1)
        ->and($metaCounts['pending'])->toBe(1);

    // Multi-status filter
    $resMulti = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/transactions?status=confirmed,failed');
    $resMulti->assertOk();
    $multiRefs = collect($resMulti->json('data'))->pluck('reference');
    expect($multiRefs)->toContain('TX-CONFIRMED')
        ->and($multiRefs)->toContain('TX-FAILED')
        ->and($multiRefs)->not->toContain('TX-PENDING');
});

test('operators monitoring provides 30 days window and detects degraded status on recent errors', function () {
    config(['payment.gateways.monetbil.enabled' => true]);

    $user = User::factory()->create();
    $tx = PaymentTransaction::create([
        'reference' => 'TX-FAIL-OP',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 1000,
        'currency' => 'XAF',
        'status' => 'failed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    // Créer une tentative échouée il y a 30 minutes (< 2 heures)
    PaymentAttempt::create([
        'transaction_id' => $tx->id,
        'attempt_number' => 1,
        'provider' => 'monetbil',
        'channel' => 'mobile_money',
        'status' => 'failed',
        'error_code' => 'INSUFFICIENT_FUNDS',
        'error_message' => 'Solde insuffisant',
        'metadata' => ['operator_code' => 'CM_MTNMOBILEMONEY'],
        'created_at' => now()->subMinutes(30),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/operators');

    $response->assertOk()
        ->assertJsonPath('data.window_days', 30);

    $monetbil = $response->json('data.providers.monetbil');
    expect($monetbil['status'])->toBe('degraded')
        ->and($monetbil['last_error'])->not->toBeNull()
        ->and($monetbil['last_error']['error_code'])->toBe('INSUFFICIENT_FUNDS');
});

test('credits ledger supports type filter and exposes total credits in circulation', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    $w1 = CreditWallet::create(['user_id' => $u1->id, 'balance' => 450, 'currency' => 'CREDIT']);
    $w2 = CreditWallet::create(['user_id' => $u2->id, 'balance' => 300, 'currency' => 'CREDIT']);

    CreditTransaction::create([
        'wallet_id' => $w1->id,
        'user_id' => $u1->id,
        'type' => 'deposit',
        'amount' => 500,
        'balance_before' => 0,
        'balance_after' => 500,
        'source' => 'pay',
        'reference' => 'REF-DEP-01',
    ]);

    CreditTransaction::create([
        'wallet_id' => $w1->id,
        'user_id' => $u1->id,
        'type' => 'debit',
        'amount' => 50,
        'balance_before' => 500,
        'balance_after' => 450,
        'source' => 'boost',
        'reference' => 'REF-USE-01',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/credits?type=deposit');

    $response->assertOk();
    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['reference'])->toBe('REF-DEP-01');

    // Total en circulation: 450 + 300 = 750
    expect($response->json('meta.total_in_circulation'))->toBe(750)
        ->and($response->json('meta.total_issued_period'))->toBe(500);
});

test('double charge claim creation links two transactions and shows them in dispute response', function () {
    $user = User::factory()->create();

    $tx1 = PaymentTransaction::create([
        'reference' => 'TX-FIRST-01',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 5000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    $tx2 = PaymentTransaction::create([
        'reference' => 'TX-SECOND-02',
        'idempotency_key' => (string) Str::uuid(),
        'user_id' => $user->id,
        'amount' => 5000,
        'currency' => 'XAF',
        'status' => 'confirmed',
        'channel' => 'mobile_money',
        'service_type' => 'boost',
        'provider' => 'monetbil',
    ]);

    $createRes = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->withHeader('X-CSRF-TOKEN', $this->csrfToken)
        ->postJson('/api/admin/payments/double-charge-claims', [
            'user_id' => $user->id,
            'transaction_id' => $tx1->id,
            'second_transaction_id' => $tx2->id,
            'reason' => 'Client débité deux fois pour le même boost',
            'disputed_amount' => 5000,
        ]);

    $createRes->assertStatus(201);
    $disputeId = $createRes->json('data.id');

    // Vérifier l'endpoint show de dispute
    $showRes = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/payments/disputes/{$disputeId}");

    $showRes->assertOk();
    expect($showRes->json('data.transaction.reference'))->toBe('TX-FIRST-01')
        ->and($showRes->json('data.second_transaction.reference'))->toBe('TX-SECOND-02');

    // Vérifier l'endpoint list de double charge claims
    $listRes = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/payments/double-charge-claims');

    $listRes->assertOk();
    $firstClaim = $listRes->json('data.0');
    expect($firstClaim['second_transaction'])->not->toBeNull()
        ->and($firstClaim['second_transaction']['reference'])->toBe('TX-SECOND-02');
});
