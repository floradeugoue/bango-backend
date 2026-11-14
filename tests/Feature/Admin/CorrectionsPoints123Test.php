<?php

use App\Enums\Visit\VisitDeclineReason;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\Support\HelpArticle;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Support\ModerationDecisionNotification;
use Database\Seeders\HelpArticleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $this->adminRole = Role::where('slug', 'admin')->firstOrFail();
    $this->userRole = Role::where('slug', 'user')->firstOrFail();

    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id, 'status' => 'active']);
    $this->user = User::factory()->create(['role_id' => $this->userRole->id, 'status' => 'active']);
});

test('point 1: get un sound absent ou supprime renvoie 404 au lieu de 422', function () {
    Passport::actingAs($this->admin, ['*']);

    $response = $this->getJson('/api/admin/sounds/non-existent-sound-uuid-999');

    $response->assertStatus(404);
});

test('point 2: la route sons sounds-catalog n existe plus au profit de sounds', function () {
    Passport::actingAs($this->admin, ['*']);

    $response = $this->getJson('/api/admin/sounds-catalog');

    $response->assertStatus(404);
});

test('point 2: users unsuspend leve la sanction active et notifie la personne', function () {
    Notification::fake();
    Passport::actingAs($this->admin, ['*']);

    $target = User::factory()->create([
        'status' => 'suspended',
        'suspended_from' => now()->subDays(2),
        'suspended_until' => now()->addDays(5),
        'suspension_reason' => 'Comportement suspect',
    ]);

    $sanction = UserAccountSanction::create([
        'user_id' => $target->id,
        'operator_id' => $this->admin->id,
        'type' => 'suspension',
        'duration_days' => 7,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->addDays(5),
        'reason' => 'Comportement suspect',
        'is_active' => true,
    ]);

    $response = $this->postJson("/api/admin/users/{$target->id}/unsuspend", [
        'reason' => 'Explications fournies et verifiees',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 'active');

    expect($target->fresh()->status)->toBe('active')
        ->and($target->fresh()->suspended_until)->toBeNull();

    // Sanction clôturée
    $sanction->refresh();
    expect($sanction->is_active)->toBeFalse()
        ->and($sanction->lifted_at)->not->toBeNull()
        ->and($sanction->lifted_by_id)->toBe($this->admin->id)
        ->and($sanction->lift_reason)->toBe('Explications fournies et verifiees');

    // Notification envoyée
    Notification::assertSentTo(
        $target,
        ModerationDecisionNotification::class,
        fn ($notification) => $notification->action === 'unsuspend_author'
    );
});

test('point 2: users unblock cloture les sanctions et notifie la personne', function () {
    Notification::fake();
    Passport::actingAs($this->admin, ['*']);

    $target = User::factory()->create([
        'status' => 'blocked',
    ]);

    $sanction = UserAccountSanction::create([
        'user_id' => $target->id,
        'operator_id' => $this->admin->id,
        'type' => 'suspension',
        'starts_at' => now()->subDays(1),
        'reason' => 'Blocage pour controle',
        'is_active' => true,
    ]);

    $response = $this->postJson("/api/admin/users/{$target->id}/unblock", [
        'reason' => 'Dossier regularise',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('status', 'active');

    expect($target->fresh()->status)->toBe('active');

    $sanction->refresh();
    expect($sanction->is_active)->toBeFalse()
        ->and($sanction->lifted_at)->not->toBeNull()
        ->and($sanction->lifted_by_id)->toBe($this->admin->id);

    Notification::assertSentTo(
        $target,
        ModerationDecisionNotification::class,
        fn ($notification) => $notification->action === 'unblock_author'
    );
});

test('point 3: VisitDeclineReason no-answer affiche 24 h', function () {
    expect(VisitDeclineReason::NoAnswer->label())->toBe('Délai de réponse de 24 h dépassé');
});

test('point 3: suivi admin des visites applique le seuil 24h et expose is_overdue_sla_24h', function () {
    [$adminUser, $sessionId] = createAdminSessionForPoints();

    $owner = User::factory()->create();
    $listing = Listing::factory()->create(['owner_id' => $owner->id]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => now()->addDays(2),
        'end_at' => now()->addDays(2)->addHour(),
        'capacity' => 1,
        'status' => 'open',
    ]);

    // Visite de 20 heures d'attente (pas en retard selon SLA 24h)
    $visit20h = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => User::factory()->create()->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Requested->value,
        'fee' => 5000,
        'currency' => 'XAF',
    ]);
    VisitReservation::where('id', $visit20h->id)->update(['created_at' => now()->subHours(20)]);

    // Visite de 26 heures d'attente (en retard selon SLA 24h)
    $visit26h = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => User::factory()->create()->id,
        'owner_id' => $owner->id,
        'status' => VisitReservationStatus::Requested->value,
        'fee' => 5000,
        'currency' => 'XAF',
    ]);
    VisitReservation::where('id', $visit26h->id)->update(['created_at' => now()->subHours(26)]);

    // Filtre overdue_sla=1 ne doit retourner que la visite >= 24h
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/listings/visits?overdue_sla=1');

    $response->assertOk()
        ->assertJsonPath('success', true);

    $items = collect($response->json('data'));
    expect($items->pluck('id')->all())->toContain($visit26h->id)
        ->and($items->pluck('id')->all())->not->toContain($visit20h->id);

    $overdueItem = $items->firstWhere('id', $visit26h->id);
    expect($overdueItem['is_overdue_sla_24h'])->toBeTrue();
});

test('point 3: HelpArticleSeeder utilise 24 heures et le tutoiement pour les visites', function () {
    (new HelpArticleSeeder)->run();

    $article = HelpArticle::where('slug', 'comment-reserver-une-visite')->firstOrFail();

    expect($article->content_markdown)->toContain('24 heures')
        ->and($article->content_markdown)->not->toContain('18 heures')
        ->and($article->content_markdown)->toContain('Rends-toi')
        ->and($article->content_markdown)->toContain('Clique')
        ->and($article->content_markdown)->toContain('ta demande');
});

function createAdminSessionForPoints(): array
{
    $role = Role::where('slug', 'admin')->firstOrFail();
    $user = User::factory()->create([
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest Test',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$user, $sessionId];
}
