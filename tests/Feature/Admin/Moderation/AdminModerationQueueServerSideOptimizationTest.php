<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Support\AppealStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Square\Hashtag;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
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

    $role = Role::where('slug', 'moderation')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'mod.test.'.uniqid().'@bango.app',
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

test('tab counters only count open reports and include comments and accounts', function () {
    $reporter = User::factory()->create();
    $author = User::factory()->create();

    $post = Post::factory()->create(['user_id' => $author->id]);
    $comment = PostComment::create([
        'user_id' => $author->id,
        'post_id' => $post->id,
        'body' => 'Commentaire de test',
    ]);

    // 1 post report ouvert
    Report::create([
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // 1 post report résolu (traité -> ne doit pas être compté dans counters.posts)
    Report::create([
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Harassment->value,
        'status' => ReportStatus::Resolved->value,
    ]);

    // 1 comment report ouvert
    Report::create([
        'reporter_id' => $reporter->id,
        'reportable_type' => PostComment::class,
        'reportable_id' => $comment->id,
        'reason' => ReportReason::HateSpeech->value,
        'status' => ReportStatus::Investigating->value,
    ]);

    // 1 account report ouvert
    Report::create([
        'reporter_id' => $reporter->id,
        'reportable_type' => User::class,
        'reportable_id' => $author->id,
        'reason' => ReportReason::Fraud->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/queue');

    $response->assertOk()
        ->assertJsonPath('counters.posts', 1)
        ->assertJsonPath('counters.comments', 1)
        ->assertJsonPath('counters.accounts', 1)
        ->assertJsonPath('counters.all', 3)
        ->assertJsonPath('meta.counts.posts', 1)
        ->assertJsonPath('meta.counts.comments', 1)
        ->assertJsonPath('meta.counts.accounts', 1);
});

test('queue search q finds by reporter and target user display_name and handle', function () {
    $reporter = User::factory()->create([
        'name' => 'Alice Reporter',
        'display_name' => 'Alice in Tech',
        'handle' => 'alicetech',
    ]);

    $targetUser = User::factory()->create([
        'name' => 'Bob Spammer',
        'display_name' => 'Bob Deals',
        'handle' => 'bobdeals237',
    ]);

    $post = Post::factory()->create(['user_id' => $targetUser->id]);

    $report = Report::create([
        'reference' => 'MOD-TEST-Q01',
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'details' => 'Détails du test de recherche',
        'status' => ReportStatus::Pending->value,
    ]);

    // Recherche par le pseudo du compte visé
    $resTarget = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/queue?q=bobdeals237');

    $resTarget->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.reference', 'MOD-TEST-Q01');

    // Recherche par le nom affiché du signalant
    $resReporter = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/queue?q=Alice in Tech');

    $resReporter->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.reference', 'MOD-TEST-Q01');
});

test('queue filters correctly with created_after and ISO dates', function () {
    $reporter = User::factory()->create();
    $post = Post::factory()->create();

    // Ancien signalement
    $oldReport = Report::create([
        'reference' => 'MOD-OLD-01',
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);
    $oldReport->created_at = now()->subDays(3);
    $oldReport->saveQuietly();

    // Récent signalement
    $recentReport = Report::create([
        'reference' => 'MOD-RECENT-01',
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);
    $recentReport->created_at = now()->subHours(2);
    $recentReport->saveQuietly();

    $isoDate = urlencode(now()->subHours(5)->toIso8601String());

    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson("/api/admin/moderation/queue?created_after={$isoDate}");

    $response->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.reference', 'MOD-RECENT-01');
});

test('my decisions endpoint includes appeal payload and filters contested decisions', function () {
    $reporter = User::factory()->create();
    $post = Post::factory()->create();

    $report1 = Report::create([
        'reference' => 'MOD-DEC-01',
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Resolved->value,
    ]);

    $decision1 = ModerationDecision::create([
        'report_id' => $report1->id,
        'operator_id' => $this->operator->id,
        'action' => 'delete_content',
        'justification' => 'Contenu frauduleux supprimé',
        'created_at' => now()->subDay(),
    ]);

    // Recours sur décision 1
    $appeal = ModerationAppeal::create([
        'reference' => 'APP-2026-TEST01',
        'user_id' => $post->user_id,
        'moderation_decision_id' => $decision1->id,
        'sanction_operator_id' => $this->operator->id,
        'sanction_type' => 'content_deletion',
        'reason' => 'contestation_legitime',
        'explanation' => 'Je n\'ai pas fait de spam',
        'status' => AppealStatus::Pending,
        'created_at' => now()->subHours(10),
    ]);

    // Décision 2 sans recours
    $report2 = Report::create([
        'reference' => 'MOD-DEC-02',
        'reporter_id' => $reporter->id,
        'reportable_type' => Post::class,
        'reportable_id' => $post->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Resolved->value,
    ]);

    ModerationDecision::create([
        'report_id' => $report2->id,
        'operator_id' => $this->operator->id,
        'action' => 'warning',
        'justification' => 'Avertissement',
        'created_at' => now(),
    ]);

    // 1. Liste complète
    $response = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/decisions/mine');

    $response->assertOk()
        ->assertJsonCount(2, 'items')
        ->assertJsonPath('items.1.appeal.reference', 'APP-2026-TEST01')
        ->assertJsonPath('items.1.appeal.status', 'pending');

    // 2. Filtre contestées uniquement
    $contestedResponse = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/decisions/mine?status=contested');

    $contestedResponse->assertOk()
        ->assertJsonCount(1, 'items')
        ->assertJsonPath('items.0.appeal.reference', 'APP-2026-TEST01');
});

test('trends endpoint filters 7-day stats by country', function () {
    $userSN = User::factory()->create(['country_code' => 'SN']);
    $userCM = User::factory()->create(['country_code' => 'CM']);

    $hashtagSN = Hashtag::create([
        'name' => '#DakarTech',
        'normalized_name' => 'dakartech',
        'posts_count' => 50,
    ]);

    $hashtagCM = Hashtag::create([
        'name' => '#DoualaBiz',
        'normalized_name' => 'doualabiz',
        'posts_count' => 40,
    ]);

    // Publication récente au Sénégal
    $postSN = Post::factory()->create([
        'user_id' => $userSN->id,
        'created_at' => now()->subDays(2),
    ]);
    $hashtagSN->posts()->attach($postSN->id, ['created_at' => now()->subDays(2)]);

    // Publication récente au Cameroun
    $postCM = Post::factory()->create([
        'user_id' => $userCM->id,
        'created_at' => now()->subDays(1),
    ]);
    $hashtagCM->posts()->attach($postCM->id, ['created_at' => now()->subDays(1)]);

    // Requête pour le Sénégal
    $responseSN = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/trends?country=SN');

    $responseSN->assertOk()
        ->assertJsonPath('meta.country', 'SN')
        ->assertJsonPath('data.top_hashtags.0.normalized_name', 'dakartech');

    // Requête pour le Cameroun
    $responseCM = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/moderation/trends?country=CM');

    $responseCM->assertOk()
        ->assertJsonPath('meta.country', 'CM')
        ->assertJsonPath('data.top_hashtags.0.normalized_name', 'doualabiz');
});
