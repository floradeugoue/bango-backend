<?php

use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\deleteJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create roles
    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);

    // Create an admin user
    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);

    \Laravel\Passport\Passport::actingAs($this->admin, ['*']);
});

test('admin can list users', function () {
    User::factory()->count(5)->create(['role_id' => $this->userRole->id]);
    
    $response = getJson('/api/users');
        
    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'display_name', 'email', 'status', 'role']
            ]
        ]);
});

test('admin can search and filter users', function () {
    $targetUser = User::factory()->create([
        'display_name' => 'John Target',
        'email' => 'target@example.com',
        'status' => 'suspended',
        'role_id' => $this->userRole->id
    ]);

    User::factory()->create([
        'display_name' => 'Other User',
        'email' => 'other@example.com',
        'status' => 'active',
        'role_id' => $this->userRole->id
    ]);

    $response = getJson('/api/users?display_name=John&status=suspended');
        
    $response->assertStatus(200)
        ->assertJsonPath('data.0.id', $targetUser->id)
        ->assertJsonCount(1, 'data');
});

test('admin can view user details without secrets', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    $response = getJson("/api/users/{$user->id}");
        
    $response->assertStatus(200)
        ->assertJsonPath('id', $user->id)
        ->assertJsonMissing(['password', 'two_factor_secret', 'backup_codes']);
});

test('admin can update user fillable fields', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    $response = patchJson("/api/users/{$user->id}", [
        'display_name' => 'Updated Name',
    ]);
        
    $response->assertStatus(200);
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'display_name' => 'Updated Name'
    ]);
});

test('admin can suspend and block user', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id, 'status' => 'active']);

    postJson("/api/users/{$user->id}/suspend")
        ->assertStatus(200);
        
    $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'suspended']);

    postJson("/api/users/{$user->id}/block")
        ->assertStatus(200);
        
    $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'blocked']);
});

test('admin can unsuspend and unblock user', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id, 'status' => 'suspended']);

    postJson("/api/users/{$user->id}/unsuspend")
        ->assertStatus(200);
        
    $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'active']);
});

test('admin can delete user', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);

    deleteJson("/api/users/{$user->id}")
        ->assertStatus(200);
        
    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

test('admin can view and revoke user sessions', function () {
    // Setup Passport clients for this test specifically
    \Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);

    $user = User::factory()->create(['role_id' => $this->userRole->id]);
    
    // Create passport token for user
    $token = $user->createToken('TestToken')->token;
    
    $response = getJson("/api/users/{$user->id}/sessions");
        
    $response->assertStatus(200)
        ->assertJsonCount(1, 'sessions')
        ->assertJsonPath('sessions.0.id', $token->id);
        
    deleteJson("/api/users/{$user->id}/sessions/{$token->id}")
        ->assertStatus(200);
        
    $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $token->id]);
});

test('admin can revoke all user sessions', function () {
    // Setup Passport clients for this test specifically
    \Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);

    $user = User::factory()->create(['role_id' => $this->userRole->id]);
    
    // Create multiple passport tokens
    $user->createToken('TestToken1');
    $user->createToken('TestToken2');
    
    $this->assertDatabaseCount('oauth_access_tokens', 2);
    
    deleteJson("/api/users/{$user->id}/sessions")
        ->assertStatus(200);
        
    $this->assertDatabaseCount('oauth_access_tokens', 0); // Both tokens should be revoked
});

test('non admin cannot access user management', function () {
    $user = User::factory()->create(['role_id' => $this->userRole->id]);
    
    \Laravel\Passport\Passport::actingAs($user, ['*']);
    
    getJson('/api/users')
        ->assertStatus(403);
});
