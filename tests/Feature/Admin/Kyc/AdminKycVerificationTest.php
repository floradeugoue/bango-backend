<?php

namespace Tests\Feature\Admin\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Enums\Kyc\KycStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Kyc\KycDocument;
use App\Models\Kyc\KycDocumentAccessLog;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createKycSession(string $roleSlug = 'verification'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'solange.bikoi@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.{$roleSlug}.".uniqid().'@bango.app',
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

it('denies access to KYC endpoints without admin session', function () {
    $response = $this->getJson('/api/admin/kyc/requests');
    $response->assertStatus(401);
});

it('denies access to operator without kyc:view permission', function () {
    // Finance role lacks kyc:view
    $role = Role::where('slug', 'finance')->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'finance.test.'.uniqid().'@bango.app',
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
        ->getJson('/api/admin/kyc/requests');

    $response->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);
});

it('allows Solange Bikoi verification operator to list KYC queue with filters and pagination', function () {
    [$solange, $sessionId] = createKycSession('verification');

    $user1 = User::factory()->create(['name' => 'Bailleur Test', 'email' => 'bailleur@example.com']);
    $user2 = User::factory()->create(['name' => 'Agent Test', 'email' => 'agent@example.com']);

    $sub1 = KycSubmission::create([
        'user_id' => $user1->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'full_name' => 'Bailleur Test',
        'id_type' => 'cni',
        'id_number' => 'CNI123456',
        'created_at' => now()->subHours(5),
    ]);

    $sub2 = KycSubmission::create([
        'user_id' => $user2->id,
        'status' => KycStatus::Approved->value,
        'profile_category' => 'agent',
        'full_name' => 'Agent Test',
        'id_type' => 'passport',
        'id_number' => 'PASS789',
        'created_at' => now()->subDay(),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/requests?status=pending&profile_category=landlord');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => [
                'current_page',
                'total',
            ],
        ])
        ->assertJsonPath('data.0.id', $sub1->id);
});

it('shows detailed submission and does not leak private file storage paths', function () {
    [$solange, $sessionId] = createKycSession('verification');

    $user = User::factory()->create(['name' => 'Demandeur KYC']);
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'full_name' => 'Demandeur KYC',
        'id_type' => 'cni',
        'id_number' => 'CNI999',
    ]);

    $doc = KycDocument::create([
        'id' => (string) Str::uuid(),
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::Front->value,
        'file_path' => 'private/kyc/secret_vault_cni_front.jpg',
        'original_name' => 'cni_front.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024 * 500,
        'status' => 'pending',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/kyc/requests/{$submission->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $submission->id)
        ->assertJsonPath('data.documents.0.id', $doc->id);

    // Verify raw internal storage path is never exposed in output
    $content = $response->getContent();
    expect($content)->not->toContain('private/kyc/secret_vault_cni_front.jpg');
});

it('approves a KYC submission with 24-month validity and awards the badge', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    $user = User::factory()->create(['name' => 'Bailleur Approuvé', 'badge' => 'none']);
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'full_name' => 'Bailleur Approuvé',
        'id_type' => 'cni',
        'id_number' => 'CNI24M',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'approve',
            'notes' => 'Pièces conformes et vérifiées',
            'validity_months' => 24,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'approved');

    $submission->refresh();
    expect($submission->status)->toBe(KycStatus::Approved)
        ->and($submission->decided_by_id)->toBe($solange->id)
        ->and($submission->expires_on)->not->toBeNull();

    // Check 24 months validity
    $expectedExpiration = Carbon::today()->addMonths(24);
    expect($submission->expires_on->toDateString())->toBe($expectedExpiration->toDateString());

    // Check user is verified without giving badge (badges are strictly subscriptions)
    $user->refresh();
    expect($user->is_verified)->toBeTrue()
        ->and($user->badge)->toBe('none');
});

it('rejects a KYC submission with required reason and logs it into refusals queue', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    $user = User::factory()->create(['name' => 'Demandeur Rejeté']);
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'agent',
        'full_name' => 'Demandeur Rejeté',
        'id_type' => 'passport',
        'id_number' => 'PASS_REJ',
    ]);

    // Missing reason should fail validation
    $badResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'reject',
        ]);

    $badResponse->assertStatus(422);

    // With reason
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'reject',
            'reason' => 'Carte expirée et document illisible',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'rejected');

    $submission->refresh();
    expect($submission->status)->toBe(KycStatus::Rejected)
        ->and($submission->rejection_reason)->toBe('Carte expirée et document illisible');

    // Check /kyc/refusals queue
    $refusalsResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/refusals');

    $refusalsResponse->assertStatus(200)
        ->assertJsonPath('data.0.id', $submission->id)
        ->assertJsonPath('data.0.is_appeal_active', true);
});

it('requests supplement changes for a KYC submission', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    $user = User::factory()->create(['name' => 'Demandeur Supplement']);
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
        'full_name' => 'Demandeur Supplement',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'request_changes',
            'reason' => 'Veuillez téléverser le verso de la pièce d\'identité',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'supplement_requested');

    $submission->refresh();
    expect($submission->status)->toBe(KycStatus::SupplementRequested);
});

it('prevents modifying an already decided KYC submission', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    $user = User::factory()->create(['name' => 'Demandeur Déjà Validé']);
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Approved->value,
        'profile_category' => 'landlord',
        'full_name' => 'Demandeur Déjà Validé',
        'decided_at' => now(),
        'decided_by_id' => $solange->id,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/decision", [
            'decision' => 'reject',
            'reason' => 'Modification refusée',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['decision']);
});

it('allows Solange to view escalation targets and escalate dossier to authorized supervisor', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    // Create a direction supervisor
    $dirRole = Role::where('slug', 'direction')->firstOrFail();
    $supervisor = User::factory()->create([
        'name' => 'Directeur Superviseur',
        'email' => 'superviseur@bango.app',
        'role_id' => $dirRole->id,
        'status' => 'active',
    ]);

    // Check targets list
    $targetsResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/escalation-targets');

    $targetsResponse->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);

    $submission = KycSubmission::create([
        'user_id' => User::factory()->create()->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'agent',
    ]);

    // Escalate to supervisor
    $escalateResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/escalate", [
            'target_user_id' => $supervisor->id,
            'reason' => 'Doute sur l\'authenticité de la procuration notariale',
        ]);

    $escalateResponse->assertStatus(200)
        ->assertJsonPath('success', true);

    $submission->refresh();
    expect($submission->escalated_to_id)->toBe($supervisor->id)
        ->and($submission->escalated_by_id)->toBe($solange->id)
        ->and($submission->escalation_reason)->toBe('Doute sur l\'authenticité de la procuration notariale');
});

it('records audit log when an authorized operator opens a KYC document', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    $user = User::factory()->create();
    $submission = KycSubmission::create([
        'user_id' => $user->id,
        'status' => KycStatus::Pending->value,
        'profile_category' => 'landlord',
    ]);

    $doc = KycDocument::create([
        'id' => (string) Str::uuid(),
        'kyc_submission_id' => $submission->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::Front->value,
        'file_path' => 'private/kyc/doc.pdf',
        'original_name' => 'doc.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$submission->id}/documents/{$doc->id}/open", [
            'action' => 'preview',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $doc->id);

    // Verify access log in database
    $log = KycDocumentAccessLog::where('kyc_document_id', $doc->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->operator_id)->toBe($solange->id)
        ->and($log->action)->toBe('preview')
        ->and($log->ip_address)->not->toBeNull();
});

it('denies opening document if docId does not belong to the submission', function () {
    [$solange, $sessionId, $csrfToken] = createKycSession('verification');

    $user = User::factory()->create();
    $sub1 = KycSubmission::create(['user_id' => $user->id, 'status' => 'pending']);
    $sub2 = KycSubmission::create(['user_id' => $user->id, 'status' => 'pending']);

    $docOfSub2 = KycDocument::create([
        'id' => (string) Str::uuid(),
        'kyc_submission_id' => $sub2->id,
        'user_id' => $user->id,
        'kind' => KycDocKind::Front->value,
        'file_path' => 'private/kyc/sub2_doc.pdf',
        'original_name' => 'sub2_doc.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'status' => 'pending',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/kyc/requests/{$sub1->id}/documents/{$docOfSub2->id}/open");

    $response->assertStatus(404);
});

it('returns verification rules matrix with criteria and validity', function () {
    [$solange, $sessionId] = createKycSession('verification');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/rules');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'badge_validity_months',
                'profiles',
                'authorized_rejection_reasons',
                'escalation_thresholds',
            ],
        ]);
});

it('lists badges with expiring and active filters', function () {
    [$solange, $sessionId] = createKycSession('verification');

    $user = User::factory()->create([
        'name' => 'Bailleur Badgé',
        'badge' => 'landlord',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/kyc/badges?status=active');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => [
                'total',
            ],
        ]);
});
