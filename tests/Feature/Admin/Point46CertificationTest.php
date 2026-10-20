<?php

use App\Http\Resources\PublicProfileResource;
use App\Http\Resources\Search\PeopleSearchResultResource;
use App\Http\Resources\UserResource;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('point 46: /admin/users/{id}/certification route grants and removes certification and resources expose certified', function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'email' => 'admin.cert.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $admin->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $user = User::factory()->create([
        'is_certified' => false,
        'certified_at' => null,
    ]);

    // 1. Grant certification via /api/admin/users/{id}/certification
    $res = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->withHeader('X-Bango-Admin-Session', $sessionId)
        ->postJson("/api/admin/users/{$user->id}/certification", [
            'reason' => 'Créateur de contenu certifié officiel',
        ]);

    $res->assertOk();
    expect($res->json('data.is_certified'))->toBeTrue();

    $user->refresh();
    expect($user->is_certified)->toBeTrue();
    expect($user->certified_at)->not->toBeNull();

    // 2. Check resources expose certified
    $userResource = (new UserResource($user))->toArray(Request::create('/api/me'));
    expect($userResource)->toHaveKey('certified');
    expect($userResource['certified'])->toBeTrue();

    $publicProfile = (new PublicProfileResource($user))->toArray(Request::create('/api/users/'.$user->id));
    expect($publicProfile)->toHaveKey('certified');
    expect($publicProfile['certified'])->toBeTrue();

    $searchResource = (new PeopleSearchResultResource($user))->toArray(Request::create('/api/search/people'));
    expect($searchResource)->toHaveKey('certified');
    expect($searchResource['certified'])->toBeTrue();

    // 3. Remove certification via /api/admin/users/{id}/certification/remove
    $removeRes = $this->withUnencryptedCookie('bango_admin_session', $sessionId)
        ->withHeader('X-CSRF-TOKEN', $csrfToken)
        ->withHeader('X-Bango-Admin-Session', $sessionId)
        ->postJson("/api/admin/users/{$user->id}/certification/remove", [
            'reason' => 'Retrait temporaire',
        ]);

    $removeRes->assertOk();
    expect($removeRes->json('data.is_certified'))->toBeFalse();

    $user->refresh();
    expect($user->is_certified)->toBeFalse();
});
