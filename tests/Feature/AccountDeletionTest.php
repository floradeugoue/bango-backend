<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test Client']);
});

it('can soft delete an account with correct password and confirmation', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123')
    ]);

    $response = $this->actingAs($user, 'api')
        ->deleteJson('/api/settings/account', [
            'password' => 'password123',
            'confirmation' => 'SUPPRIMER',
        ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);

    $this->assertSoftDeleted('users', ['id' => $user->id]);
    
    $user->refresh();
    expect($user->scheduled_for_deletion_at)->not->toBeNull();
    expect($user->scheduled_for_deletion_at->isFuture())->toBeTrue();
});

it('prevents account deletion with wrong password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123')
    ]);

    $response = $this->actingAs($user, 'api')
        ->deleteJson('/api/settings/account', [
            'password' => 'wrong',
            'confirmation' => 'SUPPRIMER',
        ]);

    $response->assertStatus(403)
             ->assertJson(['kind' => 'bad-password']);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
});

it('prevents account deletion with wrong confirmation word', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123')
    ]);

    $response = $this->actingAs($user, 'api')
        ->deleteJson('/api/settings/account', [
            'password' => 'password123',
            'confirmation' => 'DELETE',
        ]);

    $response->assertStatus(400)
             ->assertJson(['kind' => 'bad-confirmation']);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'deleted_at' => null]);
});

it('can restore a soft deleted account', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'email' => 'test@example.com',
    ]);

    // Soft delete it
    $user->delete();
    $user->scheduled_for_deletion_at = now()->addDays(30);
    $user->save();

    $response = $this->postJson('/api/auth/restore-account', [
        'identifier' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'deleted_at' => null,
        'scheduled_for_deletion_at' => null,
    ]);
});

it('prevents sign in for soft deleted accounts', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
        'email' => 'test@example.com',
    ]);

    // Soft delete it
    $user->delete();
    $user->scheduled_for_deletion_at = now()->addDays(30);
    $user->save();

    $response = $this->postJson('/api/auth/signin', [
        'identifier' => 'test@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(403)
             ->assertJson(['kind' => 'scheduled_for_deletion']);
});
