<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Boost\CampaignStatus;
use App\Enums\Boost\CreditTransactionType;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Boost\Campaign;
use App\Models\Boost\CreditWallet;
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

function createDecisionSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.decision.'.uniqid().'@bango.app',
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

it('applies a valid moderation decision and records audit log and history', function () {
    [$operator, $sessionId, $csrfToken] = createDecisionSession('moderation');

    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $author->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'hide',
            'justification' => 'Contenu non conforme aux règles communautaires.',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'action' => 'hide',
            ],
        ]);

    $this->assertDatabaseHas('moderation_decisions', [
        'report_id' => $report->id,
        'operator_id' => $operator->id,
        'action' => 'hide',
    ]);

    expect($report->fresh()->status)->toBe(ReportStatus::Resolved);
});

it('prevents duplicate or concurrent decisions on already resolved cases', function () {
    [$operator, $sessionId, $csrfToken] = createDecisionSession('moderation');
    $post = Post::factory()->create();

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Resolved->value, // déjà résolu
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'keep_online',
            'justification' => 'Tentative de re-statuer',
        ]);

    $response->assertStatus(422);
});

it('processes bulk decisions atomically and returns individual results', function () {
    [$operator, $sessionId, $csrfToken] = createDecisionSession('moderation');
    $post1 = Post::factory()->create();
    $post2 = Post::factory()->create();

    $report1 = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post1->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $report2 = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post2->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Resolved->value, // Déjà traité
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/cases/bulk-decision', [
            'case_refs' => [$report1->reference, $report2->reference],
            'action' => 'keep_online',
            'justification' => 'Traitement groupé de faux positifs',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'summary' => [
                'total' => 2,
                'succeeded' => 1,
                'skipped' => 1,
            ],
        ]);
});

it('suspends active Boost campaign and credits wallet when authorized without double refund', function () {
    [$operator, $sessionId, $csrfToken] = createDecisionSession('moderation');

    $author = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $author->id]);

    // Portefeuille de crédits BANGO pour l'auteur
    $wallet = CreditWallet::firstOrCreate(
        ['user_id' => $author->id],
        ['balance' => 500, 'currency' => 'XAF']
    );

    // Campagne Boost active sur ce contenu (unspent = 1000 - 200 = 800)
    $campaign = Campaign::create([
        'user_id' => $author->id,
        'boostable_type' => Post::class,
        'boostable_id' => (string) $post->id,
        'objective' => 'reach',
        'status' => CampaignStatus::Active,
        'budget_amount' => 1000,
        'spent_amount' => 200,
        'budget_currency' => 'XAF',
        'duration_days' => 3,
        'start_at' => now()->subDay(),
        'end_at' => now()->addDays(2),
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $author->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // Décision avec restitution explicite de crédits
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'hide',
            'justification' => 'Contenu non conforme, arrêt de diffusion et restitution du solde.',
            'metadata' => [
                'refund_credits' => true,
            ],
        ]);

    $response->assertStatus(200);

    // Vérifier que la campagne est suspendue
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Suspended);

    // Vérifier que le portefeuille a été crédité du reliquat non dépensé (500 + 800 = 1300)
    expect((int) $wallet->fresh()->balance)->toBe(1300);

    // Vérifier la présence de la transaction dans le journal financier officiel
    $this->assertDatabaseHas('credit_transactions', [
        'user_id' => $author->id,
        'type' => CreditTransactionType::CampaignRefund->value,
        'amount' => 800,
    ]);

    // Tenter de rejouer la décision sur ce même dossier
    $repeatResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'hide',
            'justification' => 'Tentative de rejeu',
            'metadata' => [
                'refund_credits' => true,
            ],
        ]);

    $repeatResponse->assertStatus(422);

    // Le solde doit rester strictement à 1300 (pas de double remboursement)
    expect((int) $wallet->fresh()->balance)->toBe(1300);
});

it('lists operator personal decisions and allows secure activity export', function () {
    [$operator, $sessionId, $csrfToken] = createDecisionSession('moderation');
    $post = Post::factory()->create();

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // Prise de décision
    $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'keep_online',
            'justification' => 'Faux positif avéré',
        ]);

    // Consulter mes décisions
    $mineResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/decisions/mine');

    $mineResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data', 'pagination']);

    // Exporter mon activité
    $exportResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/moderation/mine/export', [
            'format' => 'json',
        ]);

    $exportResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'operator' => [
                    'email' => $operator->email,
                ],
            ],
        ]);
});
