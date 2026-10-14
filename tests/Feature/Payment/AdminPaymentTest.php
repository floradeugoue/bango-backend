<?php

use App\Enums\Payment\PaymentAttemptStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use App\Enums\Payment\PaymentServiceType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Auth\Role;
use App\Models\Payment\PaymentAttempt;
use App\Models\Payment\PaymentDispute;
use App\Models\Payment\PaymentEvent;
use App\Models\Payment\PaymentTransaction;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Services\Payment\Gateways\MonetbilGateway;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

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
    ]);

    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);

    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);
    $this->regularUser = User::factory()->create(['role_id' => $this->userRole->id]);
});

it('1. rejects unauthenticated access to admin payment endpoints', function () {
    getJson('/api/admin/payments/transactions')->assertStatus(401);
    getJson('/api/admin/payments/transactions/1')->assertStatus(401);
    getJson('/api/admin/payments/transactions/1/events')->assertStatus(401);
    postJson('/api/admin/payments/transactions/1/refund', [])->assertStatus(401);
    getJson('/api/admin/payments/disputes')->assertStatus(401);
    postJson('/api/admin/payments/disputes', [])->assertStatus(401);
    getJson('/api/admin/payments/double-charge-claims')->assertStatus(401);
});

it('2. rejects access from regular non-admin users with 403 Forbidden', function () {
    Passport::actingAs($this->regularUser, ['*']);

    getJson('/api/admin/payments/transactions')->assertStatus(403);
    getJson('/api/admin/payments/transactions/1')->assertStatus(403);
    getJson('/api/admin/payments/transactions/1/events')->assertStatus(403);
    postJson('/api/admin/payments/transactions/1/refund', [
        'reason' => 'Test',
        'external_reference' => 'EXT-123',
    ])->assertStatus(403);
    getJson('/api/admin/payments/disputes')->assertStatus(403);
    getJson('/api/admin/payments/double-charge-claims')->assertStatus(403);
});

it('3. rejects actions without valid parameters or when user is not authorized', function () {
    Passport::actingAs($this->admin, ['*']);

    // Empty refund request
    postJson('/api/admin/payments/transactions/999999/refund', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason', 'external_reference']);
});

it('4. provides paginated search of transactions with correct metadata', function () {
    Passport::actingAs($this->admin, ['*']);

    // Create 3 transactions for user
    PaymentTransaction::factory()->count(3)->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    $response = getJson('/api/admin/payments/transactions?per_page=2')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'reference',
                    'service_type',
                    'amount',
                    'fee_amount',
                    'total_amount',
                    'currency',
                    'provider',
                    'status',
                    'user' => ['id', 'name', 'email'],
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
                'last_page',
            ],
        ]);

    expect($response->json('meta.total'))->toBe(3);
    expect(count($response->json('data')))->toBe(2);
});

it('5. filters transactions by status, user_id, service_type, and provider', function () {
    Passport::actingAs($this->admin, ['*']);

    $otherUser = User::factory()->create(['role_id' => $this->userRole->id]);

    $tx1 = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
        'service_type' => PaymentServiceType::Subscription,
        'provider' => PaymentProvider::Monetbil->value,
    ]);

    $tx2 = PaymentTransaction::factory()->create([
        'user_id' => $otherUser->id,
        'status' => PaymentStatus::Pending,
        'service_type' => PaymentServiceType::Boost,
        'provider' => PaymentProvider::Monetbil->value,
    ]);

    // Filter by status Confirmed
    $resStatus = getJson('/api/admin/payments/transactions?status=confirmed')->assertStatus(200);
    expect($resStatus->json('meta.total'))->toBe(1);
    expect($resStatus->json('data.0.id'))->toBe($tx1->id);

    // Filter by user_id
    $resUser = getJson('/api/admin/payments/transactions?user_id='.$otherUser->id)->assertStatus(200);
    expect($resUser->json('meta.total'))->toBe(1);
    expect($resUser->json('data.0.id'))->toBe($tx2->id);

    // Filter by service_type
    $resService = getJson('/api/admin/payments/transactions?service_type=boost')->assertStatus(200);
    expect($resService->json('meta.total'))->toBe(1);
    expect($resService->json('data.0.id'))->toBe($tx2->id);
});

it('6. validates filters and returns 404 for unknown transaction id', function () {
    Passport::actingAs($this->admin, ['*']);

    getJson('/api/admin/payments/transactions/9999999')
        ->assertStatus(404)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'TRANSACTION_NOT_FOUND',
            ],
        ]);
});

it('7. displays detailed transaction with attempts, events, and relations without leaking secrets', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
        'provider' => PaymentProvider::Monetbil->value,
    ]);

    PaymentAttempt::create([
        'transaction_id' => $tx->id,
        'attempt_number' => 1,
        'provider' => PaymentProvider::Monetbil,
        'channel' => PaymentChannel::MobileMoney,
        'external_reference' => 'MONETBIL-PAY-987654',
        'status' => PaymentAttemptStatus::Successful,
        'started_at' => now(),
    ]);

    PaymentEvent::create([
        'transaction_id' => $tx->id,
        'event_type' => 'payment.confirmed',
        'source' => PaymentEventSource::Gateway,
        'from_status' => PaymentStatus::Pending,
        'to_status' => PaymentStatus::Confirmed,
        'correlation_id' => 'CORR-TEST-123',
        'payload' => [
            'operator' => 'MTN',
            'phone' => '650000000',
            'service_secret' => 'leak_should_be_absent',
        ],
    ]);

    $response = getJson('/api/admin/payments/transactions/'.$tx->id)
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'reference',
                'status',
                'pricing_snapshot',
                'user',
                'attempts',
                'events',
                'disputes',
            ],
        ]);

    $jsonContent = $response->getContent();
    expect($jsonContent)->not->toContain('super_secret_monetbil_456');
    expect($jsonContent)->not->toContain('leak_should_be_absent');
});

it('8. provides transaction events history in append-only chronological order', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    PaymentEvent::create([
        'transaction_id' => $tx->id,
        'event_type' => 'payment.created',
        'source' => PaymentEventSource::System,
        'from_status' => null,
        'to_status' => PaymentStatus::Pending,
        'payload' => ['correlation_id' => 'CORR-1'],
    ]);

    PaymentEvent::create([
        'transaction_id' => $tx->id,
        'event_type' => 'payment.confirmed',
        'source' => PaymentEventSource::Gateway,
        'from_status' => PaymentStatus::Pending,
        'to_status' => PaymentStatus::Confirmed,
        'payload' => ['correlation_id' => 'CORR-2'],
    ]);

    $response = getJson('/api/admin/payments/transactions/'.$tx->id.'/events')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => [
                    'id',
                    'event_type',
                    'source',
                    'from_status',
                    'to_status',
                    'correlation_id',
                    'created_at',
                ],
            ],
        ]);

    expect(count($response->json('data')))->toBeGreaterThanOrEqual(2);
    $eventTypes = collect($response->json('data'))->pluck('event_type')->all();
    expect($eventTypes)->toContain('payment.created');
    expect($eventTypes)->toContain('payment.confirmed');
});

it('9. allows opening and managing a dispute dossier with status transitions and notes', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    // Create dispute
    $createRes = postJson('/api/admin/payments/disputes', [
        'transaction_id' => $tx->id,
        'reason' => 'Client conteste avoir reçu les avantages de son badge',
        'amount_contested' => 5000,
        'operator_reference' => 'OP-REF-888',
    ])->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'opened',
                'reason' => 'Client conteste avoir reçu les avantages de son badge',
                'disputed_amount' => '5000.00',
            ],
        ]);

    $disputeId = $createRes->json('data.id');
    expect($createRes->json('data.reference'))->toStartWith('BNG-DSP-');

    // Add investigation note
    postJson('/api/admin/payments/disputes/'.$disputeId.'/notes', [
        'note' => 'Vérification effectuée auprès du support client.',
    ])->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    // Transition status to under_review
    postJson('/api/admin/payments/disputes/'.$disputeId.'/status', [
        'status' => 'under_review',
        'decision_reason' => 'Dossier transmis au service financier.',
    ])->assertStatus(200)
        ->assertJsonPath('data.status', 'under_review');

    // Transition status to resolved
    postJson('/api/admin/payments/disputes/'.$disputeId.'/status', [
        'status' => 'resolved',
        'decision_reason' => 'Litige résolu avec le client par accord commercial.',
    ])->assertStatus(200)
        ->assertJsonPath('data.status', 'resolved');
});

it('10. requires a mandatory reason for sensitive administrative actions', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    // Refund without reason fails
    postJson('/api/admin/payments/transactions/'.$tx->id.'/refund', [
        'external_reference' => 'OP-REF-001',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    // Dispute note without note text fails
    $dispute = PaymentDispute::create([
        'reference' => 'BNG-DSP-TEST',
        'type' => 'dispute',
        'user_id' => $this->regularUser->id,
        'transaction_id' => $tx->id,
        'status' => 'opened',
        'reason' => 'Test reason',
    ]);

    postJson('/api/admin/payments/disputes/'.$dispute->id.'/notes', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['note']);
});

it('11. rejects refund on transactions that are not confirmed (pending or failed)', function () {
    Passport::actingAs($this->admin, ['*']);

    $pendingTx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Pending,
    ]);

    postJson('/api/admin/payments/transactions/'.$pendingTx->id.'/refund', [
        'reason' => 'Remboursement prématuré',
        'external_reference' => 'EXT-OP-111',
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'REFUND_NOT_ALLOWED',
            ],
        ]);
});

it('12. prevents duplicate refunds and excessive refund amounts', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
        'total_amount' => 5000,
    ]);

    // Excess amount
    postJson('/api/admin/payments/transactions/'.$tx->id.'/refund', [
        'reason' => 'Remboursement trop élevé',
        'external_reference' => 'EXT-OP-999',
        'amount' => 10000,
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'REFUND_NOT_ALLOWED',
            ],
        ]);

    // Successful refund
    postJson('/api/admin/payments/transactions/'.$tx->id.'/refund', [
        'reason' => 'Remboursement validé',
        'external_reference' => 'EXT-OP-100',
        'amount' => 5000,
    ])->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');

    // Duplicate refund attempt
    postJson('/api/admin/payments/transactions/'.$tx->id.'/refund', [
        'reason' => 'Tentative de second remboursement',
        'external_reference' => 'EXT-OP-101',
        'amount' => 5000,
    ])->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => [
                'code' => 'REFUND_NOT_ALLOWED',
            ],
        ]);
});

it('13. processes controlled manual refund with external reference and records audit event', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
        'total_amount' => 3000,
    ]);

    $res = postJson('/api/admin/payments/transactions/'.$tx->id.'/refund', [
        'reason' => 'Geste commercial suite à réclamation vérifiée',
        'external_reference' => 'MTN-MOMO-REFUND-443322',
    ])->assertStatus(200)
        ->assertJsonPath('data.status', 'refunded');

    // Check transaction state in database
    $tx->refresh();
    expect($tx->status)->toBe(PaymentStatus::Refunded);
    expect($tx->refunded_at)->not->toBeNull();

    // Check audit event
    $event = PaymentEvent::where('transaction_id', $tx->id)
        ->where('event_type', 'refund_processed')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->source)->toBe(PaymentEventSource::Admin);
    expect($event->payload['admin_id'])->toBe($this->admin->id);
    expect($event->payload['external_reference'])->toBe('MTN-MOMO-REFUND-443322');
    expect($event->payload['reason'])->toBe('Geste commercial suite à réclamation vérifiée');
});

it('14. registers and queries double-charge claims with operator references', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    $res = postJson('/api/admin/payments/double-charge-claims', [
        'transaction_id' => $tx->id,
        'operator_reference' => 'ORANGE-MOMO-DOUBLE-999',
        'amount_contested' => 10000,
        'reason' => 'Débité deux fois par la passerelle avec deux SMS de confirmation',
    ])->assertStatus(201);

    expect($res->json('data.reference'))->toStartWith('BNG-DCC-');
    expect($res->json('data.type'))->toBe('double_charge');
    expect($res->json('data.operator_reference'))->toBe('ORANGE-MOMO-DOUBLE-999');

    // Query double-charge-claims endpoint
    $listRes = getJson('/api/admin/payments/double-charge-claims')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'reference', 'type', 'status', 'operator_reference'],
            ],
        ]);

    expect($listRes->json('meta.total'))->toBe(1);
});

it('15. does not create duplicate SUB subscription upon dispute investigation', function () {
    Passport::actingAs($this->admin, ['*']);

    $product = SubscriptionProduct::where('code', 'landlord')->first();
    $price = $product->prices->first();

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'service_type' => PaymentServiceType::Subscription,
        'status' => PaymentStatus::Confirmed,
    ]);

    // Initial subscription
    $sub = Subscription::create([
        'user_id' => $this->regularUser->id,
        'product_id' => $product->id,
        'price_id' => $price->id,
        'badge_type' => $product->badge_type ?? 'landlord',
        'cadence' => 'monthly',
        'amount' => $price->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => false,
    ]);

    // File dispute
    postJson('/api/admin/payments/disputes', [
        'transaction_id' => $tx->id,
        'reason' => 'Investigation double débit',
        'operator_reference' => 'OP-REF-SUB',
    ])->assertStatus(201);

    // Number of subscriptions for this user must remain exactly 1
    $subsCount = Subscription::where('user_id', $this->regularUser->id)->count();
    expect($subsCount)->toBe(1);
});

it('16. traces administrative action in payment events with admin actor id', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    postJson('/api/admin/payments/transactions/'.$tx->id.'/refund', [
        'reason' => 'Traceable Admin Action Test',
        'external_reference' => 'EXT-TRACE-123',
    ])->assertStatus(200);

    $event = PaymentEvent::where('transaction_id', $tx->id)
        ->where('event_type', 'refund_processed')
        ->first();

    expect($event->source)->toBe(PaymentEventSource::Admin);
    expect($event->payload['admin_id'])->toBe($this->admin->id);
    expect($event->payload['correlation_id'])->not->toBeNull();
});

it('17. never leaks sensitive API secrets, keys, or private tokens in responses', function () {
    Passport::actingAs($this->admin, ['*']);

    $tx = PaymentTransaction::factory()->create([
        'user_id' => $this->regularUser->id,
        'status' => PaymentStatus::Confirmed,
    ]);

    $endpoints = [
        '/api/admin/payments/transactions',
        '/api/admin/payments/transactions/'.$tx->id,
        '/api/admin/payments/transactions/'.$tx->id.'/events',
        '/api/admin/payments/disputes',
        '/api/admin/payments/double-charge-claims',
    ];

    foreach ($endpoints as $endpoint) {
        $response = getJson($endpoint)->assertStatus(200);
        $content = $response->getContent();

        expect($content)->not->toContain('super_secret_monetbil_456');
        expect($content)->not->toContain('service_secret');
        expect($content)->not->toContain('password');
        expect($content)->not->toContain('private_key');
    }
});

it('18. preserves backward compatibility and non-regression on PAY, SUB, and Passport', function () {
    // Regular authenticated mobile user can still access their payments catalog and history
    Passport::actingAs($this->regularUser, ['*']);

    getJson('/api/payments/catalog')
        ->assertStatus(200)
        ->assertJsonPath('success', true);

    getJson('/api/payments/history')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta']);
});
