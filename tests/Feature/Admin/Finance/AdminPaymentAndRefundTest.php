<?php

namespace Tests\Feature\Admin\Finance;

use App\Enums\Boost\CreditTransactionType;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Boost\CreditWallet;
use App\Models\Listing\Listing;
use App\Models\Payment\LandlordPayout;
use App\Models\Payment\PaymentRefundRequest;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Admin\Finance\AdminPayoutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createFinanceSession(string $roleSlug = 'finance'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'aminata.traore@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.finance.{$roleSlug}.".uniqid().'@bango.app',
            'role_id' => $role->id,
            'status' => 'active',
        ]);
    }

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$operator, $sessionId, $csrfToken, $session];
}

it('denies access to payments without admin session', function () {
    $response = $this->getJson('/api/admin/payments');
    $response->assertStatus(401);
});

it('denies access to operator without finance permission', function () {
    $role = Role::where('slug', 'moderation')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.test.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => bin2hex(random_bytes(32)),
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments');

    $response->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);
});

it('allows Aminata Traore to list payments with filters and pagination (4.1)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $customer = User::factory()->create(['name' => 'Amadou Diallo']);
    PaymentTransaction::factory()->create([
        'user_id' => $customer->id,
        'total_amount' => 15000,
        'currency' => 'XAF',
        'status' => PaymentStatus::Confirmed,
        'provider' => PaymentProvider::Monetbil,
    ]);

    PaymentTransaction::factory()->create([
        'user_id' => $customer->id,
        'total_amount' => 5000,
        'currency' => 'XAF',
        'status' => PaymentStatus::Pending,
        'provider' => PaymentProvider::Monetbil,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments?status=confirmed');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'per_page', 'total', 'status_counts'],
        ]);

    expect(count($response->json('data')))->toBe(1);
    expect($response->json('data.0.status'))->toBe('confirmed');
});

it('returns detailed transaction with audit trail (4.2)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $tx = PaymentTransaction::factory()->confirmed()->create([
        'amount' => 10000,
        'fee_amount' => 200,
        'total_amount' => 10200,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/payments/{$tx->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.reference', $tx->reference)
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.total_amount', 10200);
});

it('exposes credits ledger and balances (4.3)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $user = User::factory()->create();
    $wallet = CreditWallet::create([
        'user_id' => $user->id,
        'balance' => 5000,
        'currency' => 'XAF',
    ]);

    $wallet->transactions()->create([
        'user_id' => $user->id,
        'type' => CreditTransactionType::Deposit,
        'amount' => 5000,
        'balance_before' => 0,
        'balance_after' => 5000,
        'source' => 'pay',
        'reference' => 'TEST-CREDIT-1',
        'description' => 'Test recharge crédits',
        'created_at' => now(),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments/credits');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'total'],
        ]);

    expect($response->json('data.0.amount'))->toBe(5000);
});

it('returns provider monitoring status (4.4)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments/operators');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'window',
                'providers' => ['monetbil', 'mtn_momo', 'orange_money'],
            ],
        ])
        ->assertJsonPath('data.providers.monetbil.status', 'operational');
});

it('lists landlord payouts (4.5)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $landlord = User::factory()->create(['name' => 'Bailleur Test']);
    LandlordPayout::create([
        'reference' => 'PAYOUT-TEST-1',
        'landlord_id' => $landlord->id,
        'amount' => 5000,
        'currency' => 'XAF',
        'status' => LandlordPayout::STATUS_SCHEDULED,
        'scheduled_at' => now()->addDay(),
        'provider' => 'monetbil',
        'channel' => 'mobile_money',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments/payouts');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta',
        ]);

    expect($response->json('data.0.reference'))->toBe('PAYOUT-TEST-1');
});

it('retries a failed landlord payout (4.6)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    $landlord = User::factory()->create(['name' => 'Bailleur Retry']);
    $payout = LandlordPayout::create([
        'reference' => 'PAYOUT-FAIL-1',
        'landlord_id' => $landlord->id,
        'amount' => 7500,
        'currency' => 'XAF',
        'status' => LandlordPayout::STATUS_FAILED,
        'scheduled_at' => now()->subDay(),
        'attempts_count' => 1,
        'last_error' => 'Timeout opérateur',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/{$payout->id}/payout/retry");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.attempts_count', 2);
});

it('schedules landlord payout at J+1 08:00 and processes via command (4.7)', function () {
    $landlord = User::factory()->create();
    $prospect = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'created_by_id' => $landlord->id,
    ]);

    $slot = VisitSlot::create([
        'id' => (string) Str::uuid(),
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now(),
        'end_at' => now()->addHour(),
        'capacity' => 1,
    ]);

    $reservation = VisitReservation::create([
        'id' => (string) Str::uuid(),
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $landlord->id,
        'status' => 'confirmed',
        'fee' => 10000,
        'currency' => 'XAF',
        'paid_at' => now(),
    ]);

    $payoutService = app(AdminPayoutService::class);
    $payout = $payoutService->schedulePayoutForVisit($reservation);

    expect($payout->status)->toBe('scheduled');
    expect($payout->scheduled_at->format('H:i:s'))->toBe('08:00:00');

    // Mettre l'échéance dans le passé pour tester le traitement planifié
    $payout->scheduled_at = now()->subHour();
    $payout->save();

    $this->artisan('payouts:process-scheduled')
        ->assertExitCode(0);

    $payout->refresh();
    expect($payout->status)->toBe('completed');
});

it('supervises subscriptions with 7-day grace period (4.8)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $user = User::factory()->create();
    $product = SubscriptionProduct::create([
        'code' => 'pro',
        'name' => 'Badge Pro',
        'badge_type' => 'pro',
        'is_active' => true,
    ]);

    $price = SubscriptionPrice::create([
        'product_id' => $product->id,
        'cadence' => 'monthly',
        'price_amount' => 15000,
        'currency' => 'XAF',
        'duration_days' => 30,
        'is_active' => true,
    ]);

    Subscription::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => 'pro',
        'cadence' => 'monthly',
        'state' => 'grace',
        'amount' => 15000,
        'currency' => 'XAF',
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subDay(),
        'grace_until' => now()->addDays(6),
        'auto_renew' => true,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments/subscriptions?in_grace=1');

    $response->assertStatus(200);
    expect($response->json('data.0.is_in_grace'))->toBeTrue();
});

it('closes an incident without moving funds (4.9)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    $tx = PaymentTransaction::factory()->confirmed()->create();

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/{$tx->id}/close", [
            'reason' => 'Incident vérifié : transaction normale sans anomalie bancaire.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'confirmed');

    // La transaction reste confirmée sans mutation de montant
    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed);
});

it('issues compensatory credits to user wallet (4.10)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    $user = User::factory()->create();
    $tx = PaymentTransaction::factory()->confirmed()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/{$tx->id}/credit", [
            'amount' => 2000,
            'reason' => 'Compensation pour interruption de service.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.amount', 2000)
        ->assertJsonPath('data.balance_after', 2000);

    $wallet = CreditWallet::where('user_id', $user->id)->first();
    expect($wallet->balance)->toBe(2000);
});

it('lists refund approvers (4.11)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/payments/refund-approvers');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'email', 'role', 'effective_permissions', 'is_active'],
            ],
        ]);
});

it('creates a refund request pending double approval (4.12)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    $tx = PaymentTransaction::factory()->confirmed()->create([
        'total_amount' => 10000,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/{$tx->id}/refund", [
            'amount' => 5000,
            'reason' => 'Demande de remboursement partiel suite à contestation.',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.amount', 5000)
        ->assertJsonPath('data.second_approval_required', true);

    // La transaction n'est PAS encore remboursée
    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Confirmed);
});

it('prevents requester from approving their own refund request (4.13)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    $tx = PaymentTransaction::factory()->confirmed()->create([
        'total_amount' => 10000,
    ]);

    $req = PaymentRefundRequest::create([
        'reference' => 'REQ-TEST-OWN',
        'transaction_id' => $tx->id,
        'requester_id' => $aminata->id, // Aminata est la demanderesse
        'status' => 'pending_approval',
        'amount' => 10000,
        'reason' => 'Demande test',
    ]);

    // Aminata tente d'approuver sa propre demande
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/{$req->id}/refund/decision", [
            'decision' => 'approved',
            'reason' => 'Auto-validation interdite',
        ]);

    $response->assertStatus(422)
        ->assertJsonFragment(['code' => 'DECISION_NOT_ALLOWED']);
});

it('allows second operator to approve refund and execute it (4.13 bis)', function () {
    [$requester] = createFinanceSession('finance');

    // Second opérateur (Directeur ou Super-Admin)
    $dirRole = Role::where('slug', 'direction')->firstOrFail();
    $decider = User::factory()->create([
        'email' => 'dir.finance.'.uniqid().'@bango.app',
        'role_id' => $dirRole->id,
        'status' => 'active',
    ]);

    $deciderSessionId = bin2hex(random_bytes(32));
    $csrf = bin2hex(random_bytes(32));
    AdminSession::create([
        'id' => $deciderSessionId,
        'user_id' => $decider->id,
        'csrf_token' => $csrf,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $tx = PaymentTransaction::factory()->confirmed()->create([
        'total_amount' => 15000,
    ]);

    $req = PaymentRefundRequest::create([
        'reference' => 'REQ-TEST-VALID',
        'transaction_id' => $tx->id,
        'requester_id' => $requester->id,
        'status' => 'pending_approval',
        'amount' => 15000,
        'reason' => 'Prestation non délivrée',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $deciderSessionId)
        ->withHeader('X-CSRF-TOKEN', $csrf)
        ->postJson("/api/admin/payments/{$req->id}/refund/decision", [
            'decision' => 'approved',
            'reason' => 'Validation conforme par le superviseur',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'approved');

    // La transaction est maintenant remboursée
    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Refunded);
});

it('allows requester to withdraw pending refund request (4.14)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    $tx = PaymentTransaction::factory()->confirmed()->create();
    $req = PaymentRefundRequest::create([
        'reference' => 'REQ-WITHDRAW-1',
        'transaction_id' => $tx->id,
        'requester_id' => $aminata->id,
        'status' => 'pending_approval',
        'amount' => 5000,
        'reason' => 'Erreur de saisie',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/payments/{$req->id}/refund/withdraw");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'withdrawn');

    $req->refresh();
    expect($req->status)->toBe('withdrawn');
});

it('generates confirmed payment receipt with cryptographic signature (4.17)', function () {
    [$aminata, $sessionId] = createFinanceSession('finance');

    $tx = PaymentTransaction::factory()->confirmed()->create([
        'total_amount' => 25000,
        'currency' => 'XAF',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/payments/{$tx->id}/receipt");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'receipt_number',
                'transaction_reference',
                'total_amount',
                'currency',
                'status',
                'integrity' => ['algorithm', 'signature', 'fingerprint_sha256'],
            ],
        ]);

    expect((int) $response->json('data.total_amount'))->toBe(25000);
    expect($response->json('data.integrity.algorithm'))->toBe('HMAC-SHA256');
});

it('previews and exports signed CSV transactions with formula protection (4.18 & 4.19)', function () {
    [$aminata, $sessionId, $csrfToken] = createFinanceSession('finance');

    PaymentTransaction::factory()->confirmed()->create([
        'reference' => '=SUM(A1:A10)', // injection test
        'total_amount' => 12000,
    ]);

    // 4.18 Preview
    $previewRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/payments/exports/preview');

    $previewRes->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['count', 'format', 'columns'],
        ]);

    // 4.19 Export
    $exportRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/payments/exports');

    $exportRes->assertStatus(200);
    $content = $exportRes->streamedContent();

    // Neutralisation de la formule d'injection
    expect($content)->toContain("'=SUM(A1:A10)");
});
