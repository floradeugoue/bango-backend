<?php

use App\Models\User;
use App\Models\ResetPasswordToken;
use Illuminate\Support\Facades\Hash;
use App\Services\Auth\ResetPasswordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->resetService = Mockery::mock(ResetPasswordService::class);
    $this->app->instance(ResetPasswordService::class, $this->resetService);
});

it('requests reset link and calls service', function () {
    $user = User::factory()->create(['email' => 'link@example.com']);

    $this->resetService->shouldReceive('sendResetLink')
        ->once()
        ->with(Mockery::on(function ($arg) use ($user) {
            return $arg->id === $user->id;
        }));

    $response = $this->postJson('/api/auth/reset-password/request', [
        'email' => 'link@example.com'
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);
});

it('does not leak existence of user', function () {
    $this->resetService->shouldReceive('sendResetLink')->never();

    $response = $this->postJson('/api/auth/reset-password/request', [
        'email' => 'unknown@example.com'
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);
});

it('resets password with valid token', function () {
    $user = User::factory()->create([
        'password' => Hash::make('old_password')
    ]);

    $token = Str::random(64);
    ResetPasswordToken::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addMinutes(60),
    ]);

    $response = $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'password' => 'new_password_123',
        'password_confirmation' => 'new_password_123'
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);

    $this->assertTrue(Hash::check('new_password_123', $user->fresh()->password));
    $this->assertNotNull(ResetPasswordToken::where('token', $token)->first()->used_at);
});

it('rejects invalid token', function () {
    $response = $this->postJson('/api/auth/reset-password', [
        'token' => 'invalid_token',
        'password' => 'new_password_123',
        'password_confirmation' => 'new_password_123'
    ]);

    $response->assertStatus(422)
             ->assertJson(['message' => 'Invalid or expired token.']);
});

it('rejects expired token', function () {
    $user = User::factory()->create();
    $token = Str::random(64);
    
    ResetPasswordToken::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->subMinutes(1),
    ]);

    $response = $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'password' => 'new_password_123',
        'password_confirmation' => 'new_password_123'
    ]);

    $response->assertStatus(422);
});

it('rejects already used token', function () {
    $user = User::factory()->create();
    $token = Str::random(64);
    
    ResetPasswordToken::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addMinutes(60),
        'used_at' => now()->subMinutes(5)
    ]);

    $response = $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'password' => 'new_password_123',
        'password_confirmation' => 'new_password_123'
    ]);

    $response->assertStatus(422);
});

it('rejects mismatched password confirmation', function () {
    $user = User::factory()->create();
    $token = Str::random(64);
    
    ResetPasswordToken::create([
        'user_id' => $user->id,
        'token' => $token,
        'expires_at' => now()->addMinutes(60),
    ]);

    $response = $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'password' => 'new_password_123',
        'password_confirmation' => 'mismatch'
    ]);

    $response->assertStatus(422);
});
