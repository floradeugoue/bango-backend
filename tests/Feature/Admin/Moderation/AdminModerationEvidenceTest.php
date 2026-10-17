<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Square\Post;
use App\Models\Support\ModerationEvidence;
use App\Models\Support\Report;
use App\Models\User;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
    Storage::fake('local');
});

function createEvidenceSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.evidence.'.uniqid().'@bango.app',
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

it('uploads moderation evidence securely and associates it with a case', function () {
    [$operator, $sessionId, $csrfToken] = createEvidenceSession('moderation');
    $post = Post::factory()->create();

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $file = UploadedFile::fake()->image('capture_preuve.png');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->post('/api/admin/moderation/uploads', [
            'file' => $file,
            'report_ref' => $report->reference,
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'report_id' => $report->id,
            ],
        ]);

    $this->assertDatabaseHas('moderation_evidences', [
        'report_id' => $report->id,
        'uploader_id' => $operator->id,
    ]);
});

it('rejects forbidden file types on upload', function () {
    [$operator, $sessionId, $csrfToken] = createEvidenceSession('moderation');

    $file = UploadedFile::fake()->create('malicious.exe', 500, 'application/x-msdownload');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/uploads', [
            'file' => $file,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('opens evidence and logs access successfully', function () {
    [$operator, $sessionId, $csrfToken] = createEvidenceSession('moderation');
    $post = Post::factory()->create();

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $evidence = ModerationEvidence::create([
        'report_id' => $report->id,
        'file_path' => 'moderation/evidences/test.png',
        'file_name' => 'test.png',
        'mime_type' => 'image/png',
        'file_size' => 1234,
        'uploader_id' => $operator->id,
        'created_at' => now(),
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/evidence/{$evidence->id}/open");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'evidence' => [
                    'id' => $evidence->id,
                    'file_name' => 'test.png',
                ],
                'case_reference' => $report->reference,
            ],
        ]);
});

it('blocks direct access to an evidence belonging to another case', function () {
    [$operator, $sessionId, $csrfToken] = createEvidenceSession('moderation');
    $post1 = Post::factory()->create();
    $post2 = Post::factory()->create();

    $caseA = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post1->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $caseB = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post2->id,
        'reason' => ReportReason::Fraud->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // Preuve appartenant au dossier B
    $evidenceB = ModerationEvidence::create([
        'report_id' => $caseB->id,
        'file_path' => 'moderation/evidences/proofB.png',
        'file_name' => 'proofB.png',
        'mime_type' => 'image/png',
        'file_size' => 5000,
        'uploader_id' => $operator->id,
        'created_at' => now(),
    ]);

    // Tentative d'accéder à la preuve B via l'URL du dossier A
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$caseA->reference}/evidence/{$evidenceB->id}/open");

    $response->assertStatus(403)
        ->assertJsonFragment(['success' => false]);
});
