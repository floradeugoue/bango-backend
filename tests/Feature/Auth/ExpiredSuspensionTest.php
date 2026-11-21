<?php

use App\Models\Support\UserAccountSanction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test Client']);
});

it('automatically lifts expired suspension and logs user in on signin', function () {
    $user = User::factory()->create([
        'email' => 'expired.suspension@example.com',
        'password' => Hash::make('ValidP@ssw0rd1'),
        'status' => 'suspended',
        'suspension_reason' => 'Suspension 7 jours',
        'suspended_from' => Carbon::now()->subDays(10),
        'suspended_until' => Carbon::now()->subDays(3), // Expired 3 days ago
    ]);

    $sanction = UserAccountSanction::create([
        'user_id' => $user->id,
        'type' => 'suspension',
        'level' => 1,
        'duration_days' => 7,
        'starts_at' => Carbon::now()->subDays(10),
        'ends_at' => Carbon::now()->subDays(3),
        'reason' => 'Suspension 7 jours',
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'expired.suspension@example.com',
        'password' => 'ValidP@ssw0rd1',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'email', 'handle'],
        ]);

    $user->refresh();
    expect($user->status)->toBe('active');
    expect($user->suspended_until)->toBeNull();
    expect($user->suspension_reason)->toBeNull();
    expect($user->suspended_from)->toBeNull();

    $sanction->refresh();
    expect($sanction->is_active)->toBeFalse();
    expect($sanction->lifted_at)->not->toBeNull();
});

it('rejects signin if suspension is still active in the future', function () {
    $user = User::factory()->create([
        'email' => 'active.suspension@example.com',
        'password' => Hash::make('ValidP@ssw0rd1'),
        'status' => 'suspended',
        'suspension_reason' => 'Suspension temporaire',
        'suspended_from' => Carbon::now()->subDay(),
        'suspended_until' => Carbon::now()->addDays(5), // Ends in 5 days
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'active.suspension@example.com',
        'password' => 'ValidP@ssw0rd1',
    ]);

    $response->assertStatus(403)
        ->assertJson(['kind' => 'suspended']);

    $user->refresh();
    expect($user->status)->toBe('suspended');
    expect($user->suspended_until)->not->toBeNull();
});

it('rejects signin if suspension is indefinite (suspended_until is null)', function () {
    $user = User::factory()->create([
        'email' => 'permanent.suspension@example.com',
        'password' => Hash::make('ValidP@ssw0rd1'),
        'status' => 'suspended',
        'suspension_reason' => 'Suspension indéfinie',
        'suspended_until' => null,
    ]);

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'permanent.suspension@example.com',
        'password' => 'ValidP@ssw0rd1',
    ]);

    $response->assertStatus(403)
        ->assertJson(['kind' => 'suspended']);

    $user->refresh();
    expect($user->status)->toBe('suspended');
});

it('lifts expired suspensions via users:lift-expired-suspensions command', function () {
    $expiredUser = User::factory()->create([
        'status' => 'suspended',
        'suspended_until' => Carbon::now()->subHours(2),
    ]);

    $activeUser = User::factory()->create([
        'status' => 'suspended',
        'suspended_until' => Carbon::now()->addDays(2),
    ]);

    Artisan::call('users:lift-expired-suspensions');

    $expiredUser->refresh();
    $activeUser->refresh();

    expect($expiredUser->status)->toBe('active');
    expect($expiredUser->suspended_until)->toBeNull();

    expect($activeUser->status)->toBe('suspended');
    expect($activeUser->suspended_until)->not->toBeNull();
});

it('evaluates User::isSuspended correctly taking suspended_until into account', function () {
    $expiredUser = new User([
        'status' => 'suspended',
        'suspended_until' => Carbon::now()->subDay(),
    ]);
    expect($expiredUser->isSuspended())->toBeFalse();

    $futureUser = new User([
        'status' => 'suspended',
        'suspended_until' => Carbon::now()->addDay(),
    ]);
    expect($futureUser->isSuspended())->toBeTrue();

    $indefiniteUser = new User([
        'status' => 'suspended',
        'suspended_until' => null,
    ]);
    expect($indefiniteUser->isSuspended())->toBeTrue();

    $activeUser = new User([
        'status' => 'active',
        'suspended_until' => null,
    ]);
    expect($activeUser->isSuspended())->toBeFalse();
});
