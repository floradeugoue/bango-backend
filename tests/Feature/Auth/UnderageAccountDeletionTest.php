<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test Client']);
});

it('immediately and permanently deletes an underage account via POST /api/profile/birthdate/underage', function () {
    $user = User::factory()->create([
        'email' => 'underage.test@example.com',
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/profile/birthdate/underage');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    // Hard deleted: not in users table at all, even with trashed
    expect(User::withTrashed()->find($user->id))->toBeNull();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('users', ['email' => 'underage.test@example.com']);
});

it('immediately and permanently deletes an underage account via PUT /api/profile/birthdate when under 18', function () {
    $user = User::factory()->create([
        'email' => 'minor.2012@example.com',
    ]);

    // 2012 date of birth (clearly under 18)
    $response = $this->actingAs($user, 'api')
        ->putJson('/api/profile/birthdate', [
            'birthDate' => '2012-06-15',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['birthDate']);

    // Hard deleted immediately
    expect(User::withTrashed()->find($user->id))->toBeNull();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
    $this->assertDatabaseMissing('users', ['email' => 'minor.2012@example.com']);
});

it('accepts snake_case birth_date and hard deletes if under 18', function () {
    $user = User::factory()->create([
        'email' => 'minor.snake@example.com',
    ]);

    $response = $this->actingAs($user, 'api')
        ->putJson('/api/profile/birthdate', [
            'birth_date' => '2014-01-01',
        ]);

    $response->assertStatus(422);

    expect(User::withTrashed()->find($user->id))->toBeNull();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

it('allows adult users to update birthdate normally', function () {
    $user = User::factory()->create([
        'birth_date' => null,
    ]);

    $response = $this->actingAs($user, 'api')
        ->putJson('/api/profile/birthdate', [
            'birthDate' => '1995-05-20',
        ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);

    $user->refresh();
    expect($user->birth_date?->format('Y-m-d') ?? (string) $user->birth_date)->toContain('1995-05-20');
});

it('rejects signup with 422 if an underage birthDate is provided', function () {
    $payload = [
        'email' => 'new.minor@example.com',
        'password' => 'StrongP@ssw0rd99',
        'birthDate' => '2012-08-10',
    ];

    $response = $this->postJson('/api/auth/signup', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['birthDate']);

    $this->assertDatabaseMissing('users', ['email' => 'new.minor@example.com']);
});
