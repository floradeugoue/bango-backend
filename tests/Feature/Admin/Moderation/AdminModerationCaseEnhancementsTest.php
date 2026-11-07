<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Listing\ListingStatus;
use App\Enums\Support\AppealStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\Support\ModerationAppeal;
use App\Models\Support\ModerationDecision;
use App\Models\Support\Report;
use App\Models\User;
use App\Notifications\Support\ModerationDecisionNotification;
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

function createCaseEnhancementSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.case.'.uniqid().'@bango.app',
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

it('returns content media and content_author populated from owner_id for listings in case detail', function () {
    [$operator, $sessionId] = createCaseEnhancementSession('moderation');

    $owner = User::factory()->create([
        'name' => 'Bailleur Ousmane',
        'email' => 'bailleur.ousmane@example.com',
    ]);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'title' => 'Appartement 3 pièces Almadies',
        'status' => ListingStatus::Published,
    ]);

    ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => 'image',
        'path' => 'listings/photo1.jpg',
        'url' => 'https://cdn.bango.app/listings/photo1.jpg',
        'thumbnail_url' => 'https://cdn.bango.app/listings/thumb1.jpg',
        'order' => 1,
    ]);

    ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => 'video',
        'path' => 'listings/visite.mp4',
        'url' => 'https://cdn.bango.app/listings/visite.mp4',
        'thumbnail_url' => 'https://cdn.bango.app/listings/poster_visite.jpg',
        'order' => 2,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $owner->id,
        'reportable_type' => Listing::class,
        'reportable_id' => $listing->id,
        'reason' => ReportReason::FakeListing->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/moderation/cases/{$report->reference}");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'content_author' => [
                    'id' => $owner->id,
                    'name' => 'Bailleur Ousmane',
                    'email' => 'bailleur.ousmane@example.com',
                ],
            ],
        ]);

    $media = $response->json('data.content.media');
    expect($media)->toBeArray()
        ->and(count($media))->toBe(2)
        ->and($media[0]['type'])->toBe('image')
        ->and($media[0]['url'])->toBe('https://cdn.bango.app/listings/photo1.jpg')
        ->and($media[0]['poster_url'])->toBe('https://cdn.bango.app/listings/thumb1.jpg')
        ->and($media[1]['type'])->toBe('video')
        ->and($media[1]['url'])->toBe('https://cdn.bango.app/listings/visite.mp4');
});

it('warn_author sends warning specifically to listing owner_id and not null', function () {
    Notification::fake();
    [$operator, $sessionId, $csrfToken] = createCaseEnhancementSession('moderation');

    $owner = User::factory()->create([
        'name' => 'Propriétaire Thierno',
        'email' => 'thierno.owner@example.com',
    ]);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $owner->id,
        'reportable_type' => Listing::class,
        'reportable_id' => $listing->id,
        'reason' => ReportReason::FakeListing->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'warn_author',
            'justification' => 'Veuillez mettre à jour les photos pour refléter l\'état réel du bien.',
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('user_account_sanctions', [
        'user_id' => $owner->id,
        'operator_id' => $operator->id,
        'type' => 'warning',
    ]);

    Notification::assertSentTo($owner, ModerationDecisionNotification::class, function ($notification) {
        return $notification->action === 'warn_author';
    });
});

it('only attaches appeal if it directly targets a moderation decision of this specific case', function () {
    [$operator, $sessionId] = createCaseEnhancementSession('moderation');

    // Case 1
    $report1 = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Listing::class,
        'reportable_id' => Listing::factory()->create()->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Resolved->value,
    ]);

    $decision1 = ModerationDecision::create([
        'report_id' => $report1->id,
        'operator_id' => $operator->id,
        'action' => 'hide',
        'justification' => 'Decision for case 1',
        'created_at' => now(),
    ]);

    // Case 2
    $report2 = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Listing::class,
        'reportable_id' => Listing::factory()->create()->id,
        'reason' => ReportReason::Fraud->value,
        'status' => ReportStatus::Resolved->value,
    ]);

    $decision2 = ModerationDecision::create([
        'report_id' => $report2->id,
        'operator_id' => $operator->id,
        'action' => 'remove',
        'justification' => 'Decision for case 2',
        'created_at' => now(),
    ]);

    // Appeal targeting Case 2's decision, with sanction_reference coincidentally matching report1->id
    $appeal2 = ModerationAppeal::create([
        'user_id' => User::factory()->create()->id,
        'sanction_type' => 'content_removal',
        'sanction_reference' => (string) $report1->id, // coincidence
        'moderation_decision_id' => $decision2->id, // targets case 2
        'reason' => 'Contestation case 2',
        'explanation' => 'Explication recours case 2',
        'status' => AppealStatus::Pending,
    ]);

    // Case 1 must NOT display appeal2
    $responseCase1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/moderation/cases/{$report1->reference}");

    $responseCase1->assertStatus(200);
    expect($responseCase1->json('data.appeal'))->toBeNull();

    // Case 2 MUST display appeal2
    $responseCase2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/moderation/cases/{$report2->reference}");

    $responseCase2->assertStatus(200);
    expect($responseCase2->json('data.appeal'))->not->toBeNull()
        ->and($responseCase2->json('data.appeal.id'))->toBe($appeal2->id);
});

it('supports request_correction with delay and automatically hides expired content via command', function () {
    [$operator, $sessionId, $csrfToken] = createCaseEnhancementSession('moderation');

    $owner = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reported_user_id' => $owner->id,
        'reportable_type' => Listing::class,
        'reportable_id' => $listing->id,
        'reason' => ReportReason::FakeListing->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // Demande de correction avec délai de 24 heures
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'request_correction',
            'justification' => 'Veuillez corriger le prix et fournir des photos du titre de séjour.',
            'correction_delay_hours' => 24,
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Investigating)
        ->and($report->action_taken)->toBe('request_correction')
        ->and($report->metadata)->toHaveKey('correction_deadline');

    $this->assertDatabaseHas('user_account_sanctions', [
        'user_id' => $owner->id,
        'type' => 'correction_requested',
        'is_active' => true,
    ]);

    // 1. Avant échéance : la commande console ne doit rien masquer
    $this->artisan('moderation:enforce-expired-corrections')
        ->assertExitCode(0);

    expect($listing->fresh()->status)->toBe(ListingStatus::Published);

    // 2. Simuler l'échéance dépassée
    $meta = $report->metadata;
    $meta['correction_deadline'] = now()->subHour()->toIso8601String();
    $report->metadata = $meta;
    $report->save();

    // 3. Après échéance : la commande console doit masquer automatiquement
    $this->artisan('moderation:enforce-expired-corrections')
        ->assertExitCode(0);

    expect($listing->fresh()->status)->toBe(ListingStatus::Draft)
        ->and($listing->fresh()->withdrawn_by_bango)->toBeTrue();

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::Resolved)
        ->and($report->action_taken)->toBe('hide');

    $this->assertDatabaseHas('moderation_decisions', [
        'report_id' => $report->id,
        'action' => 'hide',
    ]);
});

it('seeds all app reason rules and returns statistics for each rule on rules endpoint', function () {
    [$operator, $sessionId] = createCaseEnhancementSession('moderation');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/rules');

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $rules = $response->json('data');
    expect(count($rules))->toBeGreaterThanOrEqual(9);

    $codes = array_column($rules, 'code');
    expect($codes)->toContain('spam', 'fraud', 'fake_listing', 'harassment', 'inappropriate_content', 'violence', 'hate_speech');

    // Vérifier les statistiques retournées sur chaque règle
    foreach ($rules as $rule) {
        expect($rule)->toHaveKey('stats')
            ->and($rule['stats'])->toHaveKeys(['this_month', 'prev_month', 'pending', 'appeal_rate', 'overturn_rate']);
    }
});
