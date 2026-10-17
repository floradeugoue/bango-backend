<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Square\Post;
use App\Models\Support\Report;
use App\Models\User;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
});

function createModerationSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => "mod.{$roleSlug}.".uniqid().'@bango.app',
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

it('denies access to moderation queue without admin session', function () {
    $response = $this->getJson('/api/admin/moderation/queue');
    $response->assertStatus(401);
});

it('denies access to operator without moderation permission', function () {
    // Rôle support ou finance n'ayant pas moderation:view
    [$financeOp, $sessionId] = createModerationSession('finance');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/queue');

    $response->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);
});

it('allows fatou diop moderator to access moderation queue with counters and filters', function () {
    $fatou = User::where('email', 'fatou.diop@bango.app')->firstOrFail();
    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $fatou->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    // Créer des signalements
    $author = User::factory()->create();
    $reporter = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id]);

    $report = Report::create([
        'reporter_id' => $reporter->id,
        'reported_user_id' => $author->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'description' => 'Contenu publicitaire non sollicité',
        'status' => ReportStatus::Pending->value,
        'priority' => 'high',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/queue?tab=posts');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'data',
            'pagination',
            'counters' => [
                'all',
                'posts',
                'reels',
                'listings',
                'messages',
                'pending',
            ],
        ]);
});

it('returns detailed case information with polymorphic resolution', function () {
    [$operator, $sessionId] = createModerationSession('moderation');

    $author = User::factory()->create();
    $reporter = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $author->id,
        'body' => 'Post de test signalé',
    ]);

    $report = Report::create([
        'reporter_id' => $reporter->id,
        'reported_user_id' => $author->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Harassment->value,
        'description' => 'Propos déplacés et insultes',
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/moderation/cases/{$report->reference}");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'case' => [
                    'reference' => $report->reference,
                    'reason' => ReportReason::Harassment->value,
                ],
            ],
        ]);
});

it('returns 404 for unknown case reference', function () {
    [$operator, $sessionId] = createModerationSession('moderation');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/cases/MOD-9999-NOTFOUND');

    $response->assertStatus(404);
});

it('lists assignable moderation operators', function () {
    [$operator, $sessionId] = createModerationSession('moderation');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/assignees');

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => [['id', 'name', 'email']]]);
});

it('assigns reports to an operator', function () {
    [$operator, $sessionId, $csrfToken] = createModerationSession('moderation');
    $fatou = User::where('email', 'fatou.diop@bango.app')->firstOrFail();

    $post = Post::factory()->create();

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/cases/assign', [
            'case_refs' => [$report->reference],
            'operator_id' => $fatou->id,
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'assigned_count' => 1,
        ]);

    expect($report->fresh()->assigned_to)->toBe($fatou->id);
});

it('escalates a case to a supervisor with reason', function () {
    [$operator, $sessionId, $csrfToken] = createModerationSession('moderation');
    $supervisor = User::factory()->create([
        'role_id' => Role::where('slug', 'direction')->firstOrFail()->id,
    ]);
    $post = Post::factory()->create();

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Fraud->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/escalate", [
            'senior_operator_id' => $supervisor->id,
            'reason' => 'Cas complexe nécessitant un arbitrage juridique',
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $freshReport = $report->fresh();
    expect($freshReport->escalated_to)->toBe($supervisor->id)
        ->and($freshReport->escalation_reason)->toBe('Cas complexe nécessitant un arbitrage juridique');
});
