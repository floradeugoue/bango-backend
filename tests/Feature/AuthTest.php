<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('can register a new user successfully', function () {
    $response = postJson('/api/auth/signup', [
        'email' => 'test@example.com',
        'password' => 'Password123'
    ]);

    $response->assertStatus(201)
             ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
});

it('prevents weak passwords', function () {
    $response = postJson('/api/auth/signup', [
        'email' => 'weak@example.com',
        'password' => 'weak'
    ]);

    $response->assertStatus(422)
             ->assertJson([
                 'kind' => 'weak-password',
             ])
             ->assertJsonFragment(['failed' => ['min-length', 'needs-digit']]);
});

it('prevents duplicate emails during registration', function () {
    $user = User::factory()->create([
        'email' => 'taken@example.com',
        'handle' => 'taken',
        'display_name' => 'Taken User'
    ]);

    $response = postJson('/api/auth/signup', [
        'email' => 'taken@example.com',
        'password' => 'Password123'
    ]);

    $response->assertStatus(409)
             ->assertJson([
                 'kind' => 'email-taken',
                 'existingHandle' => 'taken',
                 'displayName' => 'Taken User'
             ]);
});

it('can login successfully', function () {
    $user = User::factory()->create([
        'email' => 'login@example.com',
        'password' => bcrypt('Password123')
    ]);

    $response = postJson('/api/auth/signin', [
        'identifier' => 'login@example.com',
        'password' => 'Password123'
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['token', 'user' => ['id', 'email']]);
});

it('locks out after too many failed attempts', function () {
    $user = User::factory()->create([
        'email' => 'lock@example.com',
        'password' => bcrypt('Password123')
    ]);

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/auth/signin', [
            'identifier' => 'lock@example.com',
            'password' => 'wrong'
        ]);
    }

    $response = postJson('/api/auth/signin', [
        'identifier' => 'lock@example.com',
        'password' => 'wrong'
    ]);

    $response->assertStatus(429)
             ->assertJson([
                 'kind' => 'locked'
             ]);
});
