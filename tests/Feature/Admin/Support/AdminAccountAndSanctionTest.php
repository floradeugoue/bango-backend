<?php

namespace Tests\Feature\Admin\Support;

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageStatus;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Page\Page;
use App\Models\Payment\PaymentTransaction;
use App\Models\Square\Post;
use App\Models\Support\SupportTicket;
use App\Models\Support\UserAccountSanction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
});

function createAccountAdminSession(string $roleSlug = 'support'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::where('email', 'jean-marc.kouassi@bango.app')->first();

    if (! $operator || $operator->role_id !== $role->id) {
        $operator = User::factory()->create([
            'email' => "op.account.{$roleSlug}.".uniqid().'@bango.app',
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

function createSupervisorSession(): array
{
    $role = Role::where('slug', 'admin')->firstOrFail();
    $supervisor = User::factory()->create([
        'name' => 'Superviseur Direction',
        'email' => 'supervisor.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $supervisor->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$supervisor, $sessionId, $csrfToken, $session];
}

it('lists user accounts with masked phone and search filters', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');

    $u1 = User::factory()->create([
        'name' => 'Alice Martin',
        'email' => 'alice@example.com',
        'phone' => '+2250708091011',
        'status' => 'active',
    ]);

    $u2 = User::factory()->create([
        'name' => 'Bob Dupont',
        'email' => 'bob@example.com',
        'phone' => '+2250102030405',
        'status' => 'suspended',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/accounts?search=Alice');

    $response->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.name', 'Alice Martin')
        ->assertJsonPath('data.0.masked_phone', '+225***11');

    // Vérifier que le numéro de téléphone brut n'est pas exposé
    expect($response->json('data.0.phone'))->toBeNull();
});

it('shows account details and team directory', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active', 'phone' => '+2250505050505']);

    // 1. Fiche compte
    $accountResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}");

    $accountResponse->assertStatus(200)
        ->assertJsonPath('account.id', $user->id)
        ->assertJsonPath('account.masked_phone', '+225***05');

    // 2. Annuaire de l'équipe interne
    $teamResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson('/api/admin/team');

    $teamResponse->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('provides sub-tab data for listings, contents, payments, cases, and tickets', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active']);

    // Créer des entités rattachées
    Post::create([
        'user_id' => $user->id,
        'content' => 'Mon premier post Square',
        'status' => 'published',
    ]);

    PaymentTransaction::create([
        'reference' => 'TX-2026-SUBTAB',
        'user_id' => $user->id,
        'amount' => 5000,
        'currency' => 'XOF',
        'service_type' => 'subscription',
        'status' => PaymentStatus::Confirmed,
        'provider' => 'monetbil',
        'channel' => 'mobile_money',
        'idempotency_key' => 'idemp_subtab_'.uniqid(),
    ]);

    SupportTicket::create([
        'reference' => 'TIC-2026-SUBTAB',
        'user_id' => $user->id,
        'subject' => 'Question générale',
        'category' => TicketCategory::Other,
        'status' => TicketStatus::Open,
        'priority' => TicketPriority::Normal,
    ]);

    // 1. Contents (Posts & Reels)
    $contentsRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}/contents");

    $contentsRes->assertStatus(200)
        ->assertJsonPath('data.posts.total', 1);

    // 2. Payments
    $paymentsRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}/payments");

    $paymentsRes->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.reference', 'TX-2026-SUBTAB');

    // 3. Tickets
    $ticketsRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}/tickets");

    $ticketsRes->assertStatus(200)
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.reference', 'TIC-2026-SUBTAB');
});

it('provides account timeline and 360 support summary', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active', 'phone' => '+2250707070707']);

    // 1. Timeline
    $timelineRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}/timeline");

    $timelineRes->assertStatus(200)
        ->assertJsonPath('success', true);

    // 2. Support Summary 360° (4.9)
    $summaryRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}/support-summary");

    $summaryRes->assertStatus(200)
        ->assertJsonPath('data.account.id', $user->id)
        ->assertJsonPath('data.account.masked_phone', '+225***07');
});

it('previews sanction impact without mutation', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active', 'badge' => 'pro']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->getJson("/api/admin/accounts/{$user->id}/actions/suspend_30d/preview");

    $response->assertStatus(200)
        ->assertJsonPath('data.mutation_applied', false)
        ->assertJsonPath('data.action', 'suspend_30d')
        ->assertJsonPath('data.simulated_effects.duration', '30 jours');

    // Vérifier qu'aucune mutation n'a eu lieu
    expect($user->fresh()->status)->toBe('active')
        ->and($user->fresh()->badge)->toBe('pro');
});

it('suspends an account and lifts suspension with audit trail', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active']);

    // 1. Suspension 7 jours
    $suspendRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/suspend", [
            'duration' => '7_days',
            'reason' => 'Non respect répété des règles de publication',
        ]);

    $suspendRes->assertStatus(200)
        ->assertJsonPath('data.status', 'suspended');

    $user->refresh();
    expect($user->status)->toBe('suspended')
        ->and($user->suspended_until)->not->toBeNull();

    expect(UserAccountSanction::where('user_id', $user->id)->where('is_active', true)->count())->toBe(1);

    // 2. Levée de suspension
    $liftRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/suspend/lift", [
            'reason' => 'Recours accepté suite à vérification des preuves',
        ]);

    $liftRes->assertStatus(200)
        ->assertJsonPath('data.status', 'active');

    $user->refresh();
    expect($user->status)->toBe('active')
        ->and($user->suspended_until)->toBeNull();

    expect(UserAccountSanction::where('user_id', $user->id)->where('is_active', true)->count())->toBe(0);
});

it('applies formal warnings with level 3 restriction', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/warnings", [
            'level' => 3,
            'reason' => 'Troisième avertissement formel pour comportement inapproprié',
            'apply_restriction' => true,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.level', 3);

    $user->refresh();
    expect($user->restricted_until)->not->toBeNull()
        ->and($user->restricted_until->isFuture())->toBeTrue();
});

it('extends and removes user badges', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active', 'badge' => 'agent']);

    // 1. Extension
    $extendRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/badge/extend", [
            'badge' => 'agent_premium',
            'duration_days' => 30,
            'reason' => 'Geste commercial suite à incident technique',
        ]);

    $extendRes->assertStatus(200)
        ->assertJsonPath('data.badge', 'agent_premium');

    expect($user->fresh()->badge)->toBe('agent_premium');

    // 2. Retrait
    $removeRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/badge/remove", [
            'reason' => 'Résiliation demandée par l\'utilisateur',
        ]);

    $removeRes->assertStatus(200)
        ->assertJsonPath('data.badge', 'none');

    expect($user->fresh()->badge)->toBe('none');
});

it('manages notable certification for account and page', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active', 'is_certified' => false]);
    $page = Page::create([
        'owner_id' => $user->id,
        'name' => 'Agence Immobilière Prestige',
        'handle' => 'agence_prestige_'.uniqid(),
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::None,
    ]);

    // 1. Certification de compte utilisateur
    $certUserRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/certification", [
            'type' => 'account',
            'reason' => 'Personnalité publique et créateur reconnu',
        ]);

    $certUserRes->assertStatus(200)
        ->assertJsonPath('data.is_certified', true);

    expect($user->fresh()->is_certified)->toBeTrue()
        ->and($user->fresh()->certified_at)->not->toBeNull();

    // 2. Retrait de certification de compte
    $removeUserRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/certification/remove", [
            'type' => 'account',
            'reason' => 'Changement de statut éditorial',
        ]);

    $removeUserRes->assertStatus(200)
        ->assertJsonPath('data.is_certified', false);

    expect($user->fresh()->is_certified)->toBeFalse();

    // 3. Certification de page professionnelle
    $certPageRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/certification", [
            'type' => 'page',
            'page_id' => $page->id,
            'reason' => 'Registre du commerce vérifié et certifié',
        ]);

    $certPageRes->assertStatus(200)
        ->assertJsonPath('data.certification_status', 'approved');

    expect($page->fresh()->certification_status->value)->toBe('approved');
});

it('contacts user via in_app, ticket, or sms', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create(['status' => 'active']);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/contact", [
            'channel' => 'ticket',
            'subject' => 'Vérification de votre compte BANGO',
            'message' => 'Veuillez nous transmettre un justificatif de domicile récent.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.channel', 'ticket')
        ->assertJsonPath('data.status', 'sent');

    expect(SupportTicket::where('user_id', $user->id)->count())->toBe(1);
});

it('enforces strict double approval on GDPR anonymization and prevents self-approval', function () {
    [$requester, $reqSessionId, $reqCsrfToken] = createAccountAdminSession('support');
    [$approver, $appSessionId, $appCsrfToken] = createSupervisorSession();

    $user = User::factory()->create([
        'name' => 'Jean Martin',
        'display_name' => 'JMart',
        'email' => 'jean.martin@example.com',
        'status' => 'active',
    ]);

    // 1. Demande d'anonymisation par le premier opérateur
    $requestRes = $this->withUnencryptedCookie('bango_admin_session', $reqSessionId)
        ->withHeader('X-CSRF-TOKEN', $reqCsrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/anonymize", [
            'reason' => 'Demande de droit à l\'oubli reçue par courrier recommandé',
        ]);

    $requestRes->assertStatus(201)
        ->assertJsonPath('data.status', 'pending_approval');

    $anonymizeRequestId = $requestRes->json('data.id');

    // 2. Tentative d'auto-approbation par le demandeur initial (DOIT ÊTRE INTERDITE)
    $selfApproveRes = $this->withUnencryptedCookie('bango_admin_session', $reqSessionId)
        ->withHeader('X-CSRF-TOKEN', $reqCsrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/anonymize/approve", [
            'decision_note' => 'Auto-validation interdite',
        ]);

    $selfApproveRes->assertStatus(403); // DomainException / Forbidden SELF_APPROVAL_FORBIDDEN

    // 3. Approbation par le second opérateur distinct (Superviseur / Direction)
    $validApproveRes = $this->withUnencryptedCookie('bango_admin_session', $appSessionId)
        ->withHeader('X-CSRF-TOKEN', $appCsrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/anonymize/approve", [
            'decision_note' => 'Approbation DPO conforme RGPD',
        ]);

    $validApproveRes->assertStatus(200)
        ->assertJsonPath('data.status', 'processed');

    // Vérifier que le compte a bien été anonymisé tout en préservant son ID
    $user->refresh();
    expect($user->name)->toBe('Utilisateur Supprimé')
        ->and($user->display_name)->toBe('Anonyme')
        ->and($user->status)->toBe('deleted')
        ->and($user->phone)->toBeNull();
});

it('executes security actions on account like session logout and 2FA reset', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');
    $user = User::factory()->create([
        'status' => 'active',
        'two_factor_method' => 'authenticator',
        'two_factor_secret' => 'SECRET_TEST_123',
    ]);

    // 1. Reset 2FA
    $reset2faRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/security/reset_2fa");

    $reset2faRes->assertStatus(200)
        ->assertJsonPath('data.action', 'reset_2fa');

    expect($user->fresh()->two_factor_method)->toBeNull()
        ->and($user->fresh()->two_factor_secret)->toBeNull();

    // 2. Déconnexion forcée des sessions
    $logoutRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/accounts/{$user->id}/security/logout_sessions");

    $logoutRes->assertStatus(200)
        ->assertJsonPath('data.action', 'logout_sessions');
});

it('exports accounts to CSV with formula injection protection', function () {
    [$operator, $sessionId, $csrfToken] = createAccountAdminSession('support');

    User::factory()->create([
        'name' => '=CMD|"/C calc"!A0',
        'status' => 'active',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson('/api/admin/accounts/exports');

    $response->assertStatus(200);
    $content = $response->streamedContent();

    expect($content)->toContain('ID,Nom,Pseudo,Email')
        ->and($content)->toContain("'=CMD"); // Neutralisation anti-injection
});
