<?php

use App\Models\User;
use App\Models\Otp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Services\Otp\OtpEmailService;
use App\Services\Sms\ZomloaSmsService;
use Mockery;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->emailService = Mockery::mock(OtpEmailService::class);
    $this->smsService = Mockery::mock(ZomloaSmsService::class);
    
    $this->app->instance(OtpEmailService::class, $this->emailService);
    $this->app->instance(ZomloaSmsService::class, $this->smsService);
});

it('sends forgot password otp by email', function () {
    $user = User::factory()->create(['email' => 'forgot@example.com']);

    $this->emailService->shouldReceive('send')
        ->once()
        ->with('forgot@example.com', \Mockery::type('string'));

    $response = $this->postJson('/api/auth/forgot-password', [
        'channel' => 'email',
        'identifier' => 'forgot@example.com'
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);

    $this->assertDatabaseHas('otps', [
        'identifier' => 'forgot@example.com',
        'channel' => 'email',
        'context' => 'forgot_password'
    ]);
});

it('does not leak existence of user', function () {
    $this->emailService->shouldReceive('send')->never();

    $response = $this->postJson('/api/auth/forgot-password', [
        'channel' => 'email',
        'identifier' => 'nonexistent@example.com'
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);

    $this->assertDatabaseMissing('otps', [
        'identifier' => 'nonexistent@example.com'
    ]);
});

it('verifies correct otp and returns reset token', function () {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    
    Otp::create([
        'identifier' => 'reset@example.com',
        'channel' => 'email',
        'context' => 'forgot_password',
        'code' => '123456',
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson('/api/auth/forgot-password/verify', [
        'channel' => 'email',
        'identifier' => 'reset@example.com',
        'code' => '123456'
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['success', 'reset_token']);
             
    $this->assertDatabaseMissing('otps', [
        'identifier' => 'reset@example.com'
    ]);
    
    $this->assertDatabaseHas('password_reset_tokens', [
        'email' => 'reset@example.com'
    ]);
});

it('rejects expired otp', function () {
    Otp::create([
        'identifier' => 'reset@example.com',
        'channel' => 'email',
        'context' => 'forgot_password',
        'code' => '123456',
        'expires_at' => now()->subMinutes(1),
    ]);

    $response = $this->postJson('/api/auth/forgot-password/verify', [
        'channel' => 'email',
        'identifier' => 'reset@example.com',
        'code' => '123456'
    ]);

    $response->assertStatus(422)
             ->assertJson(['kind' => 'expired']);
});

it('resets password successfully', function () {
    $user = User::factory()->create([
        'email' => 'reset2@example.com',
        'password' => Hash::make('old_password')
    ]);

    $token = Str::random(64);
    DB::table('password_reset_tokens')->insert([
        'email' => 'reset2@example.com',
        'token' => Hash::make($token),
        'created_at' => now()
    ]);

    $response = $this->postJson('/api/auth/forgot-password/reset', [
        'channel' => 'email',
        'identifier' => 'reset2@example.com',
        'token' => $token,
        'password' => 'new_password_123',
        'password_confirmation' => 'new_password_123'
    ]);

    $response->assertStatus(200)
             ->assertJson(['success' => true]);

    $this->assertTrue(Hash::check('new_password_123', $user->fresh()->password));
    
    $this->assertDatabaseMissing('password_reset_tokens', [
        'email' => 'reset2@example.com'
    ]);
});

it('rejects invalid reset token', function () {
    $user = User::factory()->create([
        'email' => 'reset3@example.com',
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => 'reset3@example.com',
        'token' => Hash::make('correct_token'),
        'created_at' => now()
    ]);

    $response = $this->postJson('/api/auth/forgot-password/reset', [
        'channel' => 'email',
        'identifier' => 'reset3@example.com',
        'token' => 'wrong_token',
        'password' => 'new_password_123',
        'password_confirmation' => 'new_password_123'
    ]);

    $response->assertStatus(422)
             ->assertJson(['message' => 'Invalid or expired reset token.']);
});
