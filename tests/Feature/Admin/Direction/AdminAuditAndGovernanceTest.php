<?php

namespace Tests\Feature\Admin\Direction;

use App\Models\Admin\AdminAuditExport;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminAuditTamperAttempt;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createDirectionSession(string $roleSlug = 'direction'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'ousmane.ba@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.direction.{$roleSlug}.".uniqid().'@bango.app',
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

it('denies access to audit without admin session', function () {
    $response = $this->getJson('/api/admin/audit');
    $response->assertStatus(401);
});

it('denies access to audit for operator without audit permission', function () {
    $role = Role::where('slug', 'moderation')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.test.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $operator->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/audit');

    $response->assertStatus(403);
});

it('allows direction operator ousmane.ba to list audit logs with verification status', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSession();

    // Create a couple of chained audit logs
    AdminAuditLog::logChainedEvent('test.event1', $operator, 'User', (string) $operator->id, 'Ousmane Ba');
    AdminAuditLog::logChainedEvent('test.event2', $operator, 'Settings', '1', 'Configuration Générale');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/audit');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'chain_status' => [
                'is_valid',
                'total_verified',
                'broken_at_id',
            ],
            'data',
            'pagination',
        ]);

    expect($response->json('chain_status.is_valid'))->toBeTrue();
});

it('verifies individual audit log details and SHA-256 hash', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSession();

    $log = AdminAuditLog::logChainedEvent('listing.certified', $operator, 'Listing', '123', 'Villa Cocody');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/audit/{$log->id}");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'id' => $log->id,
                'event' => 'listing.certified',
                'is_valid_hash' => true,
            ],
        ]);
});

it('verifies cryptographic chain and detects manual tampering in database', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSession();

    $log1 = AdminAuditLog::logChainedEvent('action.one', $operator, 'Order', '1', 'Order 1');
    $log2 = AdminAuditLog::logChainedEvent('action.two', $operator, 'Order', '2', 'Order 2');
    $log3 = AdminAuditLog::logChainedEvent('action.three', $operator, 'Order', '3', 'Order 3');

    // Initially chain is valid
    $verifyResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/audit/verify');

    $verifyResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_valid' => true,
                'broken_at_id' => null,
            ],
        ]);

    // Now simulate an illicit database update on log2 without updating hash
    \DB::table('admin_audit_logs')->where('id', $log2->id)->update([
        'event' => 'action.tampered_in_db',
    ]);

    $tamperedResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/audit/verify');

    $tamperedResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_valid' => false,
                'broken_at_id' => $log2->id,
            ],
        ]);
});

it('records an illicit edit attempt without mutating the targeted audit log', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSession();

    $targetLog = AdminAuditLog::logChainedEvent('security.critical', $operator, 'User', '42', 'Target User');
    $originalEvent = $targetLog->event;
    $originalHash = $targetLog->hash;

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/audit/{$targetLog->id}/edit-attempt", [
            'attempted_changes' => [
                'event' => 'security.tampered',
                'target_label' => 'Manipulated Target',
            ],
            'reason' => 'Tentative non autorisée détectée par le scanner WAF',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'audit_log_id' => $targetLog->id,
                'operator_id' => $operator->id,
            ],
        ]);

    // Verify targeted audit log was NEVER modified
    $freshTargetLog = AdminAuditLog::findOrFail($targetLog->id);
    expect($freshTargetLog->event)->toBe($originalEvent);
    expect($freshTargetLog->hash)->toBe($originalHash);

    // Verify a record in admin_audit_tamper_attempts was created
    expect(AdminAuditTamperAttempt::where('audit_log_id', $targetLog->id)->count())->toBe(1);

    // Verify a security event was added to the audit chain
    $securityLog = AdminAuditLog::where('event', 'security.audit_tamper_attempt_detected')->latest('id')->first();
    expect($securityLog)->not->toBeNull();
    expect($securityLog->target_id)->toBe((string) $targetLog->id);
});

it('allows consultation of role matrix and previews role assignment or revocation', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSession();

    // 1. Roles list
    $rolesResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/audit/roles');

    $rolesResponse->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'roles',
                'all_permissions',
                'total_roles',
            ],
        ]);

    // 2. Assign preview
    $targetUser = User::factory()->create(['status' => 'active']);
    $roleToAssign = Role::where('slug', 'finance')->firstOrFail();

    $assignPreview = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/audit/roles/assign-preview?user_id={$targetUser->id}&role_slug={$roleToAssign->slug}");

    $assignPreview->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'action' => 'assign',
                'target_user' => ['id' => $targetUser->id],
                'proposed_role' => ['slug' => 'finance'],
                'requires_double_approval' => true,
            ],
        ]);

    // Verify user was NOT actually modified during preview
    expect($targetUser->fresh()->role_id)->not->toBe($roleToAssign->id);
});

it('implements 4-eyes double approval for role changes and blocks self-approval', function () {
    [$requester, $sessionId, $csrfToken] = createDirectionSession();

    $targetUser = User::factory()->create(['status' => 'active']);
    $adminRole = Role::where('slug', 'admin')->firstOrFail();

    // 1. Requester submits role change request
    $requestResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/audit/roles/requests', [
            'user_id' => $targetUser->id,
            'role_slug' => $adminRole->slug,
            'action' => 'assign',
            'reason' => 'Promotion au rôle administrateur pour astreinte',
        ]);

    $requestResponse->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'pending',
                'action' => 'role.assign',
            ],
        ]);

    $approvalId = $requestResponse->json('data.id');

    // 2. Requester tries to approve their OWN request -> FORBIDDEN (403)
    $selfApproveResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/audit/roles/confirm', [
            'request_id' => $approvalId,
            'decision' => 'approve',
            'decision_reason' => 'Je valide ma propre demande',
        ]);

    $selfApproveResponse->assertStatus(403);

    // Verify target user's role is STILL unchanged
    expect($targetUser->fresh()->role_id)->not->toBe($adminRole->id);

    // 3. Second distinct operator approves the request
    $secondOperator = User::factory()->create([
        'email' => 'second.direction.'.uniqid().'@bango.app',
        'role_id' => Role::where('slug', 'direction')->first()->id,
        'status' => 'active',
    ]);

    $secondSessionId = bin2hex(random_bytes(32));
    $secondCsrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $secondSessionId,
        'user_id' => $secondOperator->id,
        'csrf_token' => $secondCsrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $confirmResponse = $this->withUnencryptedCookie('bango_admin_session', $secondSessionId)
        ->withHeader('X-CSRF-TOKEN', $secondCsrfToken)
        ->postJson('/api/admin/audit/roles/confirm', [
            'request_id' => $approvalId,
            'decision' => 'approve',
            'decision_reason' => 'Demande examinée et validée en bonne et due forme.',
        ]);

    $confirmResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'status' => 'approved',
                'validator_id' => $secondOperator->id,
            ],
        ]);

    // Verify target user's role IS now updated!
    expect($targetUser->fresh()->role_id)->toBe($adminRole->id);

    // 4. Cannot re-approve an already approved request
    $reconfirmResponse = $this->withUnencryptedCookie('bango_admin_session', $secondSessionId)
        ->withHeader('X-CSRF-TOKEN', $secondCsrfToken)
        ->postJson('/api/admin/audit/roles/confirm', [
            'request_id' => $approvalId,
            'decision' => 'approve',
        ]);

    $reconfirmResponse->assertStatus(422);
});

it('previews, generates sealed export, and verifies its cryptographic seal', function () {
    [$operator, $sessionId, $csrfToken] = createDirectionSession();

    AdminAuditLog::logChainedEvent('export.sample1', $operator, 'Test', '1', 'Sample 1');
    AdminAuditLog::logChainedEvent('export.sample2', $operator, 'Test', '2', 'Sample 2');

    // 1. Preview export
    $previewResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/audit/exports/preview');

    $previewResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'exportable' => true,
            ],
        ]);

    // 2. Download export
    $exportResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->post('/api/admin/audit/exports');

    $exportResponse->assertStatus(200);
    expect($exportResponse->headers->get('content-type'))->toContain('text/csv');

    // 3. Verify sealed export record exists
    $sealedExport = AdminAuditExport::latest('id')->firstOrFail();
    expect($sealedExport->sha256_seal)->not->toBeNull();
    expect(strlen($sealedExport->sha256_seal))->toBe(64);

    // 4. Verify authentic seal
    $verifySealResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/audit/exports/verify?seal={$sealedExport->sha256_seal}");

    $verifySealResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'is_authentic' => true,
                'seal' => $sealedExport->sha256_seal,
            ],
        ]);

    // 5. Fake seal verification fails
    $fakeSeal = str_repeat('f', 64);
    $fakeSealResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/audit/exports/verify?seal={$fakeSeal}");

    $fakeSealResponse->assertStatus(200)
        ->assertJson([
            'success' => false,
            'data' => [
                'is_authentic' => false,
            ],
        ]);
});
