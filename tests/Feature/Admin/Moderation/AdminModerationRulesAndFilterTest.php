<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Support\AppealStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationFilterLog;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use App\Notifications\Support\ModerationDecisionNotification;
use App\Services\Admin\AutomatedTextFilterService;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(ModerationRuleSeeder::class);
});

function createRulesSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => "rules.{$roleSlug}.".uniqid().'@bango.app',
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

it('allows consultation of moderation rules and official 6 app reasons mapping', function () {
    [$operator, $sessionId] = createRulesSession('moderation');

    // Consultation des règles
    $rulesResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rules');

    $rulesResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'severity', 'default_action']]]);

    // Consultation du mapping officiel des 6 motifs de l'application
    $appReasonsResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/app-reasons');

    $appReasonsResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data' => [['app_reason_code', 'app_reason_label', 'internal_rule_code']]]);
});

it('forbids standard moderator from modifying rules but allows direction/admin', function () {
    // 1. Modératrice standard (sans permission moderation:rules)
    [$moderator, $modSession, $modCsrf] = createRulesSession('moderation');

    $modResponse = $this->withUnencryptedCookie('bango_admin_session', $modSession)
        ->withHeader('X-CSRF-TOKEN', $modCsrf)
        ->postJson('/api/admin/moderation/rules', [
            'code' => 'new_rule_test',
            'name' => 'Nouvelle règle',
            'severity' => 'high',
            'default_action' => 'delete',
        ]);

    $modResponse->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);

    // 2. Opérateur de Direction (habilité)
    [$directionOp, $dirSession, $dirCsrf] = createRulesSession('direction');

    $dirResponse = $this->withUnencryptedCookie('bango_admin_session', $dirSession)
        ->withHeader('X-CSRF-TOKEN', $dirCsrf)
        ->postJson('/api/admin/moderation/rules', [
            'code' => 'new_rule_direction',
            'name' => 'Nouvelle règle direction',
            'severity' => 'critical',
            'default_action' => 'remove',
            'is_active' => true,
        ]);

    $dirResponse->assertStatus(201)
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('moderation_rules', [
        'code' => 'new_rule_direction',
    ]);
});

it('scans text and returns automated filter detection events', function () {
    [$operator, $sessionId] = createRulesSession('moderation');

    $service = app(AutomatedTextFilterService::class);
    $author = User::factory()->create([
        'display_name' => 'Jean Test',
        'city' => 'Douala',
    ]);

    // Détection de paiement hors-plateforme (1re alerte -> warned, step 1)
    $detection = $service->scanText('Paiement par monetbil direct ou western union pour finaliser', 'message', 101, $author);

    expect($detection)->not->toBeNull()
        ->and($detection['flagged'])->toBeTrue()
        ->and($detection['category'])->toBe('off_platform')
        ->and($detection['step'])->toBe(1)
        ->and($detection['action_taken'])->toBe('warned')
        ->and($detection['restricted_until'])->toBeNull();

    // Détection de fuite de numéro de téléphone fusionnée dans off_platform (2e alerte -> removed, step 2)
    $phoneDetection = $service->scanText('Mon numero est 699112233 direct', 'message', 102, $author);
    expect($phoneDetection)->not->toBeNull()
        ->and($phoneDetection['category'])->toBe('off_platform')
        ->and($phoneDetection['step'])->toBe(2)
        ->and($phoneDetection['action_taken'])->toBe('removed');

    // Détection de spam (3e alerte en 90 jours -> restricted, step 3)
    $spamDetection = $service->scanText('Cliquez ici gagnez de l\'argent facilement https://spam1.test https://spam2.test', 'post', 103, $author);
    expect($spamDetection)->not->toBeNull()
        ->and($spamDetection['category'])->toBe('spam')
        ->and($spamDetection['step'])->toBe(3)
        ->and($spamDetection['action_taken'])->toBe('restricted')
        ->and($spamDetection['restricted_until'])->not->toBeNull();

    // Consultation de l'API /moderation/filter
    $filterResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/filter?category=off_platform');

    $filterResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['items', 'data', 'pagination', 'meta']);
});

it('applies the 90-day 3-step escalation scale, removes content, restricts account for 7 days and notifies user', function () {
    Notification::fake();

    $service = app(AutomatedTextFilterService::class);
    $author = User::factory()->create([
        'display_name' => 'Auteur Escalade',
        'city' => 'Yaoundé',
    ]);

    // Alerte ancienne (> 90 jours) : ne doit pas compter dans l'échelle de 90 jours
    ModerationFilterLog::create([
        'user_id' => $author->id,
        'content_type' => 'post',
        'content_id' => 999,
        'detected_pattern' => 'arnaque',
        'category' => 'scam',
        'original_text' => 'Ancienne alerte',
        'action_taken' => 'warned',
        'step' => 1,
        'created_at' => now()->subDays(95),
    ]);

    $post1 = Post::create([
        'user_id' => $author->id,
        'body' => 'Post 1 western union',
    ]);
    $post2 = Post::create([
        'user_id' => $author->id,
        'body' => 'Post 2 arnaque garantie',
    ]);
    $comment3 = PostComment::create([
        'post_id' => $post1->id,
        'user_id' => $author->id,
        'body' => 'Commentaire 3 batard',
    ]);

    // 1er passage dans les 90 jours : avertissement (warned, step 1), contenu conservé
    $step1 = $service->scanText($post1->body, 'post', $post1->id, $author);
    expect($step1['step'])->toBe(1)
        ->and($step1['action_taken'])->toBe('warned')
        ->and($step1['restricted_until'])->toBeNull();
    expect(Post::find($post1->id))->not->toBeNull();
    expect(UserAccountSanction::where('user_id', $author->id)->count())->toBe(0);

    // 2e passage dans les 90 jours : retrait du contenu (removed, step 2) + sanction content_removal + notification
    $step2 = $service->scanText($post2->body, 'post', $post2->id, $author);
    expect($step2['step'])->toBe(2)
        ->and($step2['action_taken'])->toBe('removed')
        ->and($step2['restricted_until'])->toBeNull()
        ->and($step2['sanction_id'])->not->toBeNull();
    expect(Post::find($post2->id))->toBeNull();

    $sanction2 = UserAccountSanction::find($step2['sanction_id']);
    expect($sanction2)->not->toBeNull()
        ->and($sanction2->type)->toBe('content_removal')
        ->and($sanction2->user_id)->toBe($author->id);

    Notification::assertSentTo($author, ModerationDecisionNotification::class, function (ModerationDecisionNotification $n) {
        return $n->action === 'remove';
    });

    // 3e passage dans les 90 jours : retrait + compte restreint 7 jours (restricted, step 3) + sanction restriction + notification
    $step3 = $service->scanText($comment3->body, 'comment', $comment3->id, $author);
    expect($step3['step'])->toBe(3)
        ->and($step3['action_taken'])->toBe('restricted')
        ->and($step3['restricted_until'])->not->toBeNull()
        ->and($step3['sanction_id'])->not->toBeNull();
    expect(PostComment::find($comment3->id))->toBeNull();

    $author->refresh();
    expect($author->restricted_until)->not->toBeNull()
        ->and((int) round(now()->diffInDays($author->restricted_until)))->toBe(7);

    $sanction3 = UserAccountSanction::find($step3['sanction_id']);
    expect($sanction3)->not->toBeNull()
        ->and($sanction3->type)->toBe('restriction')
        ->and($sanction3->ends_at)->not->toBeNull();

    Notification::assertSentTo($author, ModerationDecisionNotification::class, function (ModerationDecisionNotification $n) {
        return $n->action === 'restriction';
    });
});

it('returns enriched rows, 30-day meta metrics, and supports category[], step[], and appealed=1 filters', function () {
    [$operator, $sessionId] = createRulesSession('moderation');

    $service = app(AutomatedTextFilterService::class);
    $author = User::factory()->create([
        'display_name' => 'Marie Filtre',
        'city' => 'Bafoussam',
    ]);

    // Générer les 3 étapes pour $author
    $res1 = $service->scanText('Contactez-moi sur whatsapp direct', 'message', 201, $author); // step 1, off_platform, warned
    $res2 = $service->scanText('Investissement miracle doublez votre mise', 'post', 202, $author); // step 2, scam, removed
    $res3 = $service->scanText('Cliquez ici promo exceptionnelle https://a.com https://b.com', 'post', 203, $author); // step 3, spam, restricted

    // Créer un recours (accepté/approved) sur le retrait de l'étape 2
    $appeal = ModerationAppeal::create([
        'user_id' => $author->id,
        'sanction_reference' => (string) $res2['sanction_id'],
        'sanction_type' => 'content_removal',
        'reason' => 'Ce n\'était pas une arnaque.',
        'explanation' => 'Publication légitime mal interprétée par le filtre.',
        'status' => AppealStatus::Approved,
        'resolution' => 'revoked',
    ]);

    // 1. Vérifier les lignes enrichies et les métriques meta sur 30 jours
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/filter');

    $response->assertStatus(200)
        ->assertJsonPath('meta.by_step.1', 1)
        ->assertJsonPath('meta.by_step.2', 1)
        ->assertJsonPath('meta.by_step.3', 1)
        ->assertJsonPath('meta.removed', 2)
        ->assertJsonPath('meta.restricted', 1)
        ->assertJsonPath('meta.active_restrictions', 1)
        ->assertJsonPath('meta.appeal_acceptance_rate', 100);

    $meta = $response->json('meta');
    expect($meta['daily'])->toBeArray()
        ->and(count($meta['daily']))->toBe(30);

    $todayBucket = collect($meta['daily'])->firstWhere('date', now()->toDateString());
    expect($todayBucket)->not->toBeNull()
        ->and($todayBucket['warned'])->toBe(1)
        ->and($todayBucket['removed'])->toBe(2); // step 2 (removed) + step 3 (restricted inclut retrait)

    $rows = collect($response->json('data'));
    $rowStep2 = $rows->firstWhere('id', $res2['log_id']);
    expect($rowStep2)->not->toBeNull()
        ->and($rowStep2['step'])->toBe(2)
        ->and($rowStep2['author'])->toBe([
            'id' => $author->id,
            'display_name' => 'Marie Filtre',
            'city' => 'Bafoussam',
        ])
        ->and($rowStep2['appeal'])->toBe([
            'id' => $appeal->id,
            'status' => AppealStatus::Approved->value,
        ]);

    $rowStep3 = $rows->firstWhere('id', $res3['log_id']);
    expect($rowStep3)->not->toBeNull()
        ->and($rowStep3['step'])->toBe(3)
        ->and($rowStep3['restricted_until'])->not->toBeNull()
        ->and($rowStep3['appeal'])->toBeNull();

    // 2. Filtre multi-valeurs category[] (scam + spam)
    $catResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/filter?category[]=scam&category[]=spam');
    $catResponse->assertStatus(200);
    expect(count($catResponse->json('data')))->toBe(2);

    // 3. Filtre multi-valeurs step[] (step 2 et 3)
    $stepResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/filter?step[]=2&step[]=3');
    $stepResponse->assertStatus(200);
    expect(count($stepResponse->json('data')))->toBe(2);

    // 4. Filtre appealed=1
    $appealedResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/filter?appealed=1');
    $appealedResponse->assertStatus(200);
    expect(count($appealedResponse->json('data')))->toBe(1)
        ->and($appealedResponse->json('data.0.id'))->toBe($res2['log_id']);
});
