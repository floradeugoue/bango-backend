<?php

namespace Tests\Feature\Admin\Moderation;

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Admin\AutomatedTextFilterService;
use Database\Seeders\ModerationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $author = User::factory()->create();

    // Détection de paiement hors-plateforme
    $detection = $service->scanText('Paiement par monetbil direct ou western union pour finaliser', 'message', 101, $author);

    expect($detection)->not->toBeNull()
        ->and($detection['flagged'])->toBeTrue()
        ->and($detection['category'])->toBe('off_platform');

    // Détection de fuite de numéro de téléphone
    $phoneDetection = $service->scanText('Mon numero est 699112233 direct', 'message', 102, $author);
    expect($phoneDetection)->not->toBeNull()
        ->and($phoneDetection['category'])->toBe('phone_leak');

    // Consultation de l'API /moderation/filter
    $filterResponse = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/filter?category=off_platform');

    $filterResponse->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data', 'pagination']);
});
