<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Enums\Support\ReportReason;
use App\Enums\Support\ReportStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Page\Page;
use App\Models\Support\Report;
use App\Models\User;
use App\Notifications\Page\PageSuspendedNotification;
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

function createPageModerationSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.page.'.uniqid().'@bango.app',
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

it('suspending a reported page suspends page status without touching owner account and without deleting the page', function () {
    Notification::fake();
    [$operator, $sessionId, $csrfToken] = createPageModerationSession('moderation');

    $owner = User::factory()->create([
        'name' => 'Propriétaire Agence',
        'email' => 'owner.agence@example.com',
        'status' => 'active',
    ]);

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Agence Immobilière Teranga',
        'handle' => 'teranga_immo_'.uniqid(),
        'description' => 'Location et vente de villas de luxe.',
        'sector' => PageSector::RealEstateAgency->value,
        'status' => PageStatus::Active->value,
        'certification_status' => PageCertificationStatus::None->value,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Page::class,
        'reportable_id' => $page->id,
        'reason' => ReportReason::Fraud->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'suspend_author',
            'justification' => 'Annonces frauduleuses répétées sur cette page.',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    // 1. La page doit être suspendue opérationnellement (pages.status = suspended)
    $freshPage = $page->fresh();
    expect($freshPage)->not->toBeNull();
    expect($freshPage->status)->toBe(PageStatus::Suspended);
    expect($freshPage->metadata['suspension']['reason'])->toBe('Annonces frauduleuses répétées sur cette page.');
    expect($freshPage->metadata['suspension']['suspended_by_id'])->toBe($operator->id);

    // 2. La page NE DOIT PAS être supprimée
    $this->assertDatabaseHas('pages', [
        'id' => $page->id,
        'deleted_at' => null,
    ]);

    // 3. Le compte personnel du propriétaire NE DOIT PAS être suspendu
    $freshOwner = $owner->fresh();
    expect($freshOwner->status)->toBe('active');
    expect($freshOwner->suspended_until)->toBeNull();
    expect($freshOwner->suspended_from)->toBeNull();

    // 4. Aucune sanction de suspension sur le compte personnel
    $this->assertDatabaseMissing('user_account_sanctions', [
        'user_id' => $owner->id,
        'type' => 'suspension',
    ]);

    // 5. Notifications envoyées au propriétaire
    Notification::assertSentTo($owner, PageSuspendedNotification::class);
    Notification::assertSentTo($owner, ModerationDecisionNotification::class, function ($n) {
        return $n->action === 'suspend_page';
    });
});

it('supports explicit suspend_page and unsuspend_page actions on reported page', function () {
    Notification::fake();
    [$operator, $sessionId, $csrfToken] = createPageModerationSession('moderation');

    $owner = User::factory()->create(['status' => 'active']);
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Boutique Test',
        'handle' => 'boutique_test_'.uniqid(),
        'sector' => PageSector::Commerce->value,
        'status' => PageStatus::Active->value,
        'certification_status' => PageCertificationStatus::None->value,
    ]);

    $report1 = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Page::class,
        'reportable_id' => $page->id,
        'reason' => ReportReason::InappropriateContent->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // Action suspend_page
    $res1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report1->reference}/decision", [
            'action' => 'suspend_page',
            'justification' => 'Suspension pour vérification.',
        ]);

    $res1->assertStatus(200);
    expect($page->fresh()->status)->toBe(PageStatus::Suspended);
    expect($owner->fresh()->status)->toBe('active');

    // Nouveau dossier pour lever la suspension
    $report2 = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Page::class,
        'reportable_id' => $page->id,
        'reason' => ReportReason::Other->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // Action unsuspend_page
    $res2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report2->reference}/decision", [
            'action' => 'unsuspend_page',
            'justification' => 'Mise en conformité vérifiée par le support.',
        ]);

    $res2->assertStatus(200);
    expect($page->fresh()->status)->toBe(PageStatus::Active);
    expect($page->fresh()->metadata['suspension']['lifted_by_id'])->toBe($operator->id);
    expect($owner->fresh()->status)->toBe('active');
});

it('supports warn_page action without deleting page or suspending owner', function () {
    Notification::fake();
    [$operator, $sessionId, $csrfToken] = createPageModerationSession('moderation');

    $owner = User::factory()->create(['status' => 'active']);
    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Tech Services',
        'handle' => 'tech_services_'.uniqid(),
        'sector' => PageSector::Services->value,
        'status' => PageStatus::Active->value,
        'certification_status' => PageCertificationStatus::None->value,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Page::class,
        'reportable_id' => $page->id,
        'reason' => ReportReason::Spam->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'warn_page',
            'justification' => 'Veuillez limiter la fréquence des publications promotionnelles.',
        ]);

    $response->assertStatus(200);
    expect($page->fresh()->status)->toBe(PageStatus::Active);
    expect($owner->fresh()->status)->toBe('active');
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'deleted_at' => null]);

    Notification::assertSentTo($owner, ModerationDecisionNotification::class, function ($n) {
        return $n->action === 'warn_page';
    });
});

it('rejects remove and hide actions on pages with descriptive validation message', function () {
    [$operator, $sessionId, $csrfToken] = createPageModerationSession('moderation');

    $page = Page::create([
        'owner_id' => User::factory()->create()->id,
        'name' => 'Page Protégée',
        'handle' => 'page_protegee_'.uniqid(),
        'sector' => PageSector::Services->value,
        'status' => PageStatus::Active->value,
        'certification_status' => PageCertificationStatus::None->value,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Page::class,
        'reportable_id' => $page->id,
        'reason' => ReportReason::Harassment->value,
        'status' => ReportStatus::Pending->value,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/cases/{$report->reference}/decision", [
            'action' => 'remove',
            'justification' => 'Tentative de suppression de page.',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['case']);

    // Vérifier que la page n'a pas été supprimée
    $this->assertDatabaseHas('pages', ['id' => $page->id, 'deleted_at' => null]);
});

it('returns target_type, available_actions, counters and tab filter for pages in queue and case detail', function () {
    [$operator, $sessionId] = createPageModerationSession('moderation');

    $owner = User::factory()->create([
        'name' => 'Mamadou Diallo',
        'email' => 'mamadou.page@example.com',
    ]);

    $page = Page::create([
        'owner_id' => $owner->id,
        'name' => 'Dakar Auto Services',
        'handle' => 'dakar_auto_'.uniqid(),
        'description' => 'Vente et entretien de véhicules d\'occasion.',
        'sector' => PageSector::Commerce->value,
        'status' => PageStatus::Active->value,
        'certification_status' => PageCertificationStatus::Approved->value,
    ]);

    $report = Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => Page::class,
        'reportable_id' => $page->id,
        'reason' => ReportReason::FakeListing->value,
        'status' => ReportStatus::Pending->value,
    ]);

    // 1. Consultation de la file avec tab=pages
    $queueResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/queue?tab=pages');

    $queueResponse->assertStatus(200)
        ->assertJsonPath('counters.pages', 1);

    $items = $queueResponse->json('items');
    expect(count($items))->toBeGreaterThanOrEqual(1);

    $pageItem = collect($items)->firstWhere('reference', $report->reference);
    expect($pageItem)->not->toBeNull();
    expect($pageItem['target_type'])->toBe('page');
    expect($pageItem['target']['type'])->toBe('page');
    expect($pageItem['target']['target_type'])->toBe('page');
    expect($pageItem['target']['display_name'])->toBe('Dakar Auto Services');
    expect($pageItem['available_actions'])->toContain('warn_page', 'suspend_page', 'unsuspend_page');

    // 2. Consultation détaillée de la fiche (GET /moderation/cases/{ref})
    $detailResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/moderation/cases/{$report->reference}");

    $detailResponse->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'target_type' => 'page',
                'content' => [
                    'id' => $page->id,
                    'name' => 'Dakar Auto Services',
                ],
                'content_author' => [
                    'id' => $owner->id,
                    'name' => 'Mamadou Diallo',
                    'email' => 'mamadou.page@example.com',
                ],
            ],
        ]);

    $availableActions = $detailResponse->json('data.available_actions');
    expect($availableActions)->toBeArray();
    $actionKeys = array_column($availableActions, 'action');
    expect($actionKeys)->toContain('warn_page', 'suspend_page', 'unsuspend_page', 'keep_online', 'request_correction');
});
