<?php

namespace Tests\Feature\Admin;

use App\Enums\Page\PageRole;
use App\Models\Admin\AdminAuditLog;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Neighbourhood;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use Database\Seeders\GeoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(GeoSeeder::class);
});

function createBatchAdminUser(string $roleSlug = 'direction'): User
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();

    return User::factory()->create([
        'email' => "admin.{$roleSlug}.".uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
        'password' => Hash::make('Password123!Secure'),
    ]);
}

function createBatchAdminSession(User $user): array
{
    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$sessionId, $csrfToken];
}

test('point 1: verification de l integrite sha256 de la chaine d audit', function () {
    $operator = createBatchAdminUser('direction');
    [$sessionId, $csrfToken] = createBatchAdminSession($operator);

    // Initialisation d'une chaîne propre
    AdminAuditLog::logChainedEvent(
        event: 'test.init',
        operator: $operator,
        targetType: 'User',
        targetId: (string) $operator->id,
        targetLabel: 'Initialisation test 1'
    );

    AdminAuditLog::logChainedEvent(
        event: 'test.second_action',
        operator: $operator,
        targetType: 'User',
        targetId: (string) $operator->id,
        targetLabel: 'Action suivante test 2'
    );

    // Vérification via le service / model
    $chainStatus = AdminAuditLog::verifyChain();
    expect($chainStatus['is_valid'])->toBeTrue()
        ->and($chainStatus['broken_at_id'])->toBeNull();

    // Vérification via la route GET /api/admin/audit/verify
    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/audit/verify');

    $res->assertStatus(200)
        ->assertJsonPath('data.is_valid', true);

    // Altération intentionnelle d'un log pour simuler une modification illicite
    $firstLog = AdminAuditLog::orderBy('id', 'asc')->first();
    DB::table('admin_audit_logs')->where('id', $firstLog->id)->update([
        'event' => 'tampered.event',
    ]);

    $tamperedStatus = AdminAuditLog::verifyChain();
    expect($tamperedStatus['is_valid'])->toBeFalse()
        ->and($tamperedStatus['broken_at_id'])->toBe($firstLog->id);
});

test('point 2: un credit manuel rejoue est verse une seule fois et journalise une seule fois', function () {
    $operator = createBatchAdminUser('direction');
    [$sessionId, $csrfToken] = createBatchAdminSession($operator);

    $userRole = Role::where('slug', 'user')->firstOrFail();
    $targetUser = User::factory()->create(['role_id' => $userRole->id, 'status' => 'active']);

    $idempotencyKey = 'deposit_idemp_key_'.uniqid();

    // Premier dépôt
    $res1 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson("/api/admin/payments/credits/wallets/{$targetUser->id}/deposit", [
            'amount' => 500,
            'reason' => 'Geste commercial initial',
            'idempotency_key' => $idempotencyKey,
        ]);

    $res1->assertStatus(201);

    // Deuxième dépôt (rejoué avec la même idempotency_key)
    $res2 = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson("/api/admin/payments/credits/wallets/{$targetUser->id}/deposit", [
            'amount' => 500,
            'reason' => 'Geste commercial initial',
            'idempotency_key' => $idempotencyKey,
        ]);

    $res2->assertStatus(201);

    // Vérification du nombre de logs d'audit : exactement 1 seule ligne finance.wallet_manual_credit
    $logsCount = AdminAuditLog::where('event', 'finance.wallet_manual_credit')
        ->where('target_id', (string) $targetUser->id)
        ->count();

    expect($logsCount)->toBe(1);
});

test('point 3: la fiche admin d une annonce rend withdrawn_by_bango et le motif', function () {
    $operator = createBatchAdminUser('direction');
    [$sessionId] = createBatchAdminSession($operator);

    $userRole = Role::where('slug', 'user')->firstOrFail();
    $author = User::factory()->create(['role_id' => $userRole->id]);

    $listing = Listing::factory()->create([
        'owner_id' => $author->id,
        'withdrawn_by_bango' => true,
        'withdrawn_by_bango_at' => now()->subDay(),
        'bango_withdrawal_reason' => 'Non conformité manifeste du dossier',
    ]);

    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson("/api/admin/listings/{$listing->reference}");

    $res->assertStatus(200)
        ->assertJsonPath('data.withdrawn_by_bango', true)
        ->assertJsonPath('data.withdrawn_by_bango_reason', 'Non conformité manifeste du dossier');

    expect($res->json('data.withdrawn_by_bango_at'))->not->toBeNull();
});

test('point 4: les quartiers rendent users_count et listings_count', function () {
    $operator = createBatchAdminUser('direction');
    [$sessionId] = createBatchAdminSession($operator);

    $city = City::where('slug', 'douala')->firstOrFail();

    $neighbourhood = Neighbourhood::create([
        'city_id' => $city->id,
        'name' => 'Quartier Test '.uniqid(),
        'slug' => 'quartier-test-'.uniqid(),
        'is_active' => true,
    ]);

    $userRole = Role::where('slug', 'user')->firstOrFail();

    // Créer des utilisateurs dans ce quartier
    User::factory()->count(2)->create([
        'role_id' => $userRole->id,
        'neighbourhood_id' => $neighbourhood->id,
    ]);

    // Créer des annonces dans ce quartier
    Listing::factory()->count(3)->create([
        'owner_id' => User::factory()->create(['role_id' => $userRole->id])->id,
        'neighbourhood' => $neighbourhood->name,
    ]);

    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/settings/areas?q='.urlencode($neighbourhood->name));

    $res->assertStatus(200);

    $items = collect($res->json('data'));
    $found = $items->firstWhere('id', $neighbourhood->id);

    expect($found)->not->toBeNull()
        ->and($found['users_count'])->toBe(2)
        ->and($found['listings_count'])->toBe(3);
});

test('point 5: assignation d une conversation de page par conversation et restitution de assigned_to', function () {
    $userRole = Role::where('slug', 'user')->firstOrFail();

    $owner = User::factory()->create(['role_id' => $userRole->id]);
    $collaborator = User::factory()->create(['role_id' => $userRole->id]);

    $page = Page::factory()->create(['owner_id' => $owner->id]);

    $member = PageMember::factory()->create([
        'page_id' => $page->id,
        'user_id' => $collaborator->id,
        'role' => PageRole::Editor,
    ]);

    $conversation = Conversation::factory()->create([
        'page_id' => $page->id,
        'created_by_id' => $owner->id,
        'assigned_to_id' => null,
    ]);

    Passport::actingAs($owner, ['*']);

    // 1. Assigner la conversation au collaborateur via member_id
    $resAssign = $this->postJson("/api/pages/{$page->id}/conversations/{$conversation->id}/assign", [
        'member_id' => $member->id,
    ]);

    $resAssign->assertStatus(200)
        ->assertJsonPath('data.assigned_to_id', $collaborator->id)
        ->assertJsonPath('data.assigned_to.id', $collaborator->id)
        ->assertJsonPath('data.assigned_to.member_id', $member->id);

    // 2. Vérifier que la boîte de réception de la page restitue assigned_to
    $resInbox = $this->getJson("/api/pages/{$page->id}/inbox");
    $resInbox->assertStatus(200);
    $inboxItems = collect($resInbox->json('data'));
    $convInbox = $inboxItems->firstWhere('id', $conversation->id);
    expect($convInbox)->not->toBeNull()
        ->and($convInbox['assigned_to_id'])->toBe($collaborator->id)
        ->and($convInbox['assigned_to']['id'])->toBe($collaborator->id);

    // 3. Désassigner la conversation
    $resUnassign = $this->postJson("/api/pages/{$page->id}/conversations/{$conversation->id}/assign", [
        'member_id' => null,
    ]);

    $resUnassign->assertStatus(200)
        ->assertJsonPath('data.assigned_to_id', null)
        ->assertJsonPath('data.assigned_to', null);

    $conversation->refresh();
    expect($conversation->assigned_to_id)->toBeNull();
});
