<?php

use App\Enums\Admin\AdminPermission;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    // Enregistrement d'une route de test protégée par permission
    Route::middleware(['admin.session', 'admin.permission:'.AdminPermission::FinanceAction->value])
        ->get('/test/admin/finance-only', fn () => response()->json(['secret' => 'financial_data_access']));

    Route::middleware(['admin.session', 'admin.permission:'.AdminPermission::ModerationAction->value])
        ->get('/test/admin/moderation-only', fn () => response()->json(['secret' => 'moderation_data_access']));

    Route::middleware(['admin.session', 'admin.permission:'.AdminPermission::RolesManage->value])
        ->get('/test/admin/roles-only', fn () => response()->json(['secret' => 'roles_data_access']));
});

function createOperatorWithRole(string $roleSlug): array
{
    $role = Role::where('slug', $roleSlug)->firstOrFail();
    $user = User::factory()->create([
        'email' => "operator.{$roleSlug}@bango.app",
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    $session = AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    return [$user, $sessionId, $csrfToken, $session];
}

it('ensures each role receives its expected matrix of permissions', function () {
    $directionRole = Role::where('slug', 'direction')->first();
    $financeRole = Role::where('slug', 'finance')->first();
    $moderationRole = Role::where('slug', 'moderation')->first();
    $supportRole = Role::where('slug', 'support')->first();
    $verificationRole = Role::where('slug', 'verification')->first();

    $directionPerms = AdminPermission::defaultForRole('direction');
    $financePerms = AdminPermission::defaultForRole('finance');
    $moderationPerms = AdminPermission::defaultForRole('moderation');
    $supportPerms = AdminPermission::defaultForRole('support');
    $verificationPerms = AdminPermission::defaultForRole('verification');

    // Direction a les approbations 4 yeux et gestion des rôles
    expect($directionPerms)->toContain(AdminPermission::ApprovalsAction->value);
    expect($directionPerms)->toContain(AdminPermission::RolesManage->value);

    // Finance a la gestion financière mais pas la modération de contenu
    expect($financePerms)->toContain(AdminPermission::FinanceAction->value);
    expect($financePerms)->not->toContain(AdminPermission::ModerationAction->value);

    // Modération a la modération de contenu mais pas la gestion financière
    expect($moderationPerms)->toContain(AdminPermission::ModerationAction->value);
    expect($moderationPerms)->not->toContain(AdminPermission::FinanceAction->value);

    // Support a le support client mais pas les approbations sensibles
    expect($supportPerms)->toContain(AdminPermission::SupportAction->value);
    expect($supportPerms)->not->toContain(AdminPermission::ApprovalsAction->value);

    // Vérification a la vérification KYC
    expect($verificationPerms)->toContain(AdminPermission::VerificationAction->value);
    expect($verificationPerms)->not->toContain(AdminPermission::FinanceAction->value);
});

it('allows access when operator has required permission and forbids access with 403 when lacking it', function () {
    // 1. Opérateur Finance tente d'accéder à la route finance -> 200 OK
    [, $financeSessionId] = createOperatorWithRole('finance');
    $allowedResponse = $this->withUnencryptedCookie('bango_admin_session', $financeSessionId)
        ->getJson('/test/admin/finance-only');
    $allowedResponse->assertStatus(200)
        ->assertJsonFragment(['secret' => 'financial_data_access']);

    // 2. Opérateur Support tente d'accéder à la route finance -> 403 Forbidden
    [, $supportSessionId] = createOperatorWithRole('support');
    $forbiddenResponse = $this->withUnencryptedCookie('bango_admin_session', $supportSessionId)
        ->getJson('/test/admin/finance-only');
    $forbiddenResponse->assertStatus(403)
        ->assertJsonFragment(['code' => 'ADMIN_PERMISSION_DENIED']);

    // 3. Opérateur Modération tente d'accéder à la modération -> 200 OK
    [, $modoSessionId] = createOperatorWithRole('moderation');
    $modoAllowed = $this->withUnencryptedCookie('bango_admin_session', $modoSessionId)
        ->getJson('/test/admin/moderation-only');
    $modoAllowed->assertStatus(200);

    // 4. Opérateur Modération tente d'accéder à la gestion des rôles -> 403 Forbidden
    $modoForbidden = $this->withUnencryptedCookie('bango_admin_session', $modoSessionId)
        ->getJson('/test/admin/roles-only');
    $modoForbidden->assertStatus(403);
});

it('returns effective permissions array in /auth/session', function () {
    [$user, $sessionId] = createOperatorWithRole('moderation');

    $response = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->getJson('/api/admin/auth/session');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'operator' => [
                'id',
                'name',
                'email',
                'role',
                'permissions',
            ],
            'session',
            'workspace',
        ]);

    $perms = $response->json('operator.permissions');
    expect($perms)->toBeArray();
    expect($perms)->toContain(AdminPermission::ModerationAction->value);
    expect($perms)->not->toContain(AdminPermission::FinanceAction->value);
});

it('recalculates permissions dynamically when user role changes', function () {
    [$user, $sessionId] = createOperatorWithRole('support');

    expect($user->hasPermission(AdminPermission::FinanceAction->value))->toBeFalse();

    // Promotion de l'opérateur vers Finance
    $financeRole = Role::where('slug', 'finance')->first();
    $user->update(['role_id' => $financeRole->id]);

    $user->refresh();
    expect($user->hasPermission(AdminPermission::FinanceAction->value))->toBeTrue();
    expect($user->hasPermission(AdminPermission::SupportAction->value))->toBeFalse();
});
