<?php

namespace Tests\Feature\Admin\Moderation;

use App\Enums\Support\AppealStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Support\ModerationAppeal;
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

function createAppealsSession(string $roleSlug = 'moderation'): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $operator = User::factory()->create([
        'email' => 'mod.appeal.'.uniqid().'@bango.app',
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

it('lists pending moderation appeals with pagination and filters', function () {
    [$operator, $sessionId] = createAppealsSession('moderation');

    $user = User::factory()->create();

    $appeal = ModerationAppeal::create([
        'user_id' => $user->id,
        'sanction_type' => 'content_hidden',
        'reason' => 'Contestation de modération',
        'explanation' => 'Je conteste cette décision, mon contenu respectait les CGU.',
        'status' => AppealStatus::Pending,
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/moderation/appeals');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure(['data', 'pagination']);
});

it('strictly forbids an operator from reviewing an appeal against their own initial sanction', function () {
    // Opérateur A ayant prononcé la sanction
    [$operatorA, $sessionA, $csrfA] = createAppealsSession('moderation');

    $user = User::factory()->create();

    $appeal = ModerationAppeal::create([
        'user_id' => $user->id,
        'sanction_type' => 'account_suspended',
        'sanction_operator_id' => $operatorA->id, // Auteur de la sanction initiale
        'reason' => 'Contestation suspension',
        'explanation' => 'Mon compte a été suspendu par erreur.',
        'status' => AppealStatus::Pending,
    ]);

    // L'opérateur A tente de juger son propre recours
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionA)
        ->withHeader('X-CSRF-TOKEN', $csrfA)
        ->postJson("/api/admin/moderation/appeals/{$appeal->reference}/resolution", [
            'resolution' => 'upheld',
            'decision_notes' => 'Je confirme ma propre décision initiale.',
        ]);

    // Doit être formellement rejeté avec 403 (Principe de séparation des rôles)
    $response->assertStatus(403);
    expect($appeal->fresh()->status)->toBe(AppealStatus::Pending);
});

it('allows a third-party operator to resolve an appeal and lift or uphold the sanction', function () {
    // Opérateur A ayant prononcé la sanction
    [$operatorA] = createAppealsSession('moderation');
    // Opérateur B tiers arbitre
    [$operatorB, $sessionB, $csrfB] = createAppealsSession('moderation');

    $user = User::factory()->create(['status' => 'suspended']);

    $appeal = ModerationAppeal::create([
        'user_id' => $user->id,
        'sanction_type' => 'account_suspended',
        'sanction_operator_id' => $operatorA->id,
        'reason' => 'Profil légitime',
        'explanation' => 'Erreur manifeste sur mon profil.',
        'status' => AppealStatus::Pending,
    ]);

    // Opérateur B examine et lève la sanction (revoked)
    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionB)
        ->withHeader('X-CSRF-TOKEN', $csrfB)
        ->postJson("/api/admin/moderation/appeals/{$appeal->reference}/resolution", [
            'resolution' => 'revoked',
            'decision_notes' => 'Après réexamen par un tiers, le compte est conforme. Levée de la suspension.',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'resolution' => 'revoked',
                'status' => AppealStatus::Approved->value,
            ],
        ]);

    $freshAppeal = $appeal->fresh();
    expect($freshAppeal->status)->toBe(AppealStatus::Approved)
        ->and($freshAppeal->resolution)->toBe('revoked')
        ->and($freshAppeal->reviewed_by)->toBe($operatorB->id)
        ->and($user->fresh()->status)->toBe('active');
});

it('prevents resolving an already processed appeal', function () {
    [$operator, $sessionId, $csrfToken] = createAppealsSession('moderation');

    $appeal = ModerationAppeal::create([
        'user_id' => User::factory()->create()->id,
        'sanction_type' => 'content_hidden',
        'reason' => 'Contestation',
        'explanation' => 'Explication de contestation',
        'status' => AppealStatus::Rejected, // Déjà rejeté
        'resolution' => 'upheld',
    ]);

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->postJson("/api/admin/moderation/appeals/{$appeal->reference}/resolution", [
            'resolution' => 'revoked',
            'decision_notes' => 'Tentative de réouverture',
        ]);

    $response->assertStatus(422);
});
