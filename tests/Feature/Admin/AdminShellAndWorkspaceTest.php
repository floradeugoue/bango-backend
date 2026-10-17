<?php

use App\Models\Admin\AdminApproval;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createSessionForRole(string $roleSlug): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => "op.{$roleSlug}.".uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

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

it('returns navigation counters filtered by operator permissions', function () {
    // 1. Pour la Direction (accès à tous les compteurs)
    [$directionOp, $directionSession] = createSessionForRole('direction');

    $response = $this->withUnencryptedCookie('bango_admin_session', $directionSession)
        ->getJson('/api/admin/shell/counters');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'counters' => [
                'moderation_pending',
                'kyc_pending',
                'payments_intervention_required',
                'payments_disputes_open',
                'support_tickets_pending',
            ],
            'calculated_at',
        ]);

    // 2. Pour le Support (pas d'accès aux paiements)
    [$supportOp, $supportSession] = createSessionForRole('support');

    $supportResponse = $this->withUnencryptedCookie('bango_admin_session', $supportSession)
        ->getJson('/api/admin/shell/counters');

    $supportResponse->assertStatus(200);
    expect($supportResponse->json('counters.payments_intervention_required'))->toBeNull();
    expect($supportResponse->json('counters.support_tickets_pending'))->not->toBeNull();
});

it('returns the covered countries list with metrics for authorized operators', function () {
    [$directionOp, $directionSession] = createSessionForRole('direction');

    $response = $this->withUnencryptedCookie('bango_admin_session', $directionSession)
        ->getJson('/api/admin/shell/countries');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'countries' => [
                '*' => ['code', 'name', 'phone_code', 'currency', 'is_active'],
            ],
            'total_covered',
        ]);

    $codes = collect($response->json('countries'))->pluck('code')->all();
    expect($codes)->toContain('CM', 'SN', 'CI', 'GH', 'NG');
});

it('lists paginated notifications with strict ownership isolation and marks all as read', function () {
    [$op1, $session1, $csrf1] = createSessionForRole('moderation');
    [$op2, $session2, $csrf2] = createSessionForRole('moderation');

    // Crée 3 notifications pour op1 et 2 pour op2
    for ($i = 1; $i <= 3; $i++) {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\AdminAlertNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $op1->id,
            'data' => json_encode(['title' => "Notification Op1 - {$i}"]),
            'read_at' => null,
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    for ($i = 1; $i <= 2; $i++) {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\Notifications\AdminAlertNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $op2->id,
            'data' => json_encode(['title' => "Notification Op2 - {$i}"]),
            'read_at' => null,
            'created_at' => now()->subMinutes($i),
            'updated_at' => now()->subMinutes($i),
        ]);
    }

    // op1 consulte ses notifications : total = 3, unread = 3
    $response = $this->withUnencryptedCookie('bango_admin_session', $session1)
        ->getJson('/api/admin/notifications');

    $response->assertStatus(200)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.unread_count', 3);

    // op1 marque tout comme lu
    $readAllResponse = $this->withUnencryptedCookie('bango_admin_session', $session1)
        ->withUnencryptedCookie('bango_admin_csrf', $csrf1)
        ->withHeader('X-CSRF-Token', $csrf1)
        ->postJson('/api/admin/notifications/read-all');

    $readAllResponse->assertStatus(200)
        ->assertJsonPath('marked_as_read_count', 3);

    // Vérifie l'isolation : op2 a toujours ses 2 notifications non lues
    $op2Response = $this->withUnencryptedCookie('bango_admin_session', $session2)
        ->getJson('/api/admin/notifications');

    $op2Response->assertStatus(200)
        ->assertJsonPath('meta.unread_count', 2);
});

it('performs transverse search across authorized domains without leaking sensitive secrets', function () {
    [$directionOp, $directionSession] = createSessionForRole('direction');

    // Crée un utilisateur de test
    User::factory()->create([
        'name' => 'SearchTarget Client',
        'email' => 'searchtarget@example.com',
    ]);

    $searchResponse = $this->withUnencryptedCookie('bango_admin_session', $directionSession)
        ->getJson('/api/admin/search?q=SearchTarget');

    $searchResponse->assertStatus(200)
        ->assertJsonStructure([
            'query',
            'category',
            'results' => [
                'comptes',
            ],
            'total_found',
        ]);

    $accounts = $searchResponse->json('results.comptes');
    expect(count($accounts))->toBeGreaterThanOrEqual(1);
    expect($accounts[0]['title'])->toContain('SearchTarget');
    // Vérifie qu'aucun mot de passe ou secret n'est exposé
    expect($accounts[0])->not->toHaveKey('password');
    expect($accounts[0])->not->toHaveKey('two_factor_secret');
});

it('returns workspace metrics and workload volume for the operator', function () {
    [$directionOp, $directionSession] = createSessionForRole('direction');

    $response = $this->withUnencryptedCookie('bango_admin_session', $directionSession)
        ->getJson('/api/admin/me/workspace');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'operator' => ['id', 'name', 'role'],
            'today_metrics',
            'priority_items',
            'seven_days_workload',
            'workspace_updated_at',
        ]);
});

it('provides approvals queue and enforces 4-eyes separation of duties', function () {
    [$requesterOp, $requesterSession] = createSessionForRole('moderation');
    [$validatorOp, $validatorSession] = createSessionForRole('direction');

    // Crée une demande d'approbation soumise par requesterOp
    $approval = AdminApproval::create([
        'action' => 'payout_override',
        'approvable_type' => 'payment',
        'approvable_id' => '999',
        'requester_id' => $requesterOp->id,
        'payload' => ['amount' => 50000],
        'status' => 'pending',
    ]);

    // 1. Le demandeur consulte la file : la demande est dans ses requêtes soumises (my_submitted_requests), pas dans pending_for_my_approval
    $requesterResponse = $this->withUnencryptedCookie('bango_admin_session', $requesterSession)
        ->getJson('/api/admin/me/approvals');

    $requesterResponse->assertStatus(200);
    $mySubmitted = $requesterResponse->json('my_submitted_requests');
    expect(count($mySubmitted))->toBeGreaterThanOrEqual(1);
    $myReq = collect($mySubmitted)->firstWhere('id', $approval->id);
    expect($myReq)->not->toBeNull();
    // Le demandeur n'a pas la demande dans sa file d'approbation (séparation stricte des tâches)
    $pendingForMe = $requesterResponse->json('pending_for_my_approval');
    expect(collect($pendingForMe)->firstWhere('id', $approval->id))->toBeNull();

    // 2. Le valideur de la direction consulte la file : la demande est dans pending_for_my_approval et can_validate est vrai
    $validatorResponse = $this->withUnencryptedCookie('bango_admin_session', $validatorSession)
        ->getJson('/api/admin/me/approvals');

    $validatorResponse->assertStatus(200);
    $pendingForValidator = $validatorResponse->json('pending_for_my_approval');
    expect(count($pendingForValidator))->toBeGreaterThanOrEqual(1);
    $vItem = collect($pendingForValidator)->firstWhere('id', $approval->id);
    expect($vItem)->not->toBeNull();
    expect($vItem['can_validate'])->toBeTrue();
});
