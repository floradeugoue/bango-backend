<?php

use App\Models\User;
use App\Models\Otp;
use App\Enums\OtpChannel;
use App\Enums\OtpFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Laravel Personal Access Client', '--no-interaction' => true]);
    $user = User::factory()->create();
    Passport::actingAs($user);
});

// --- TESTS SMS ---

it('can request an OTP via SMS', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200)
    ]);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    $this->assertDatabaseHas('otps', [
        'identifier' => '+237600000001',
        'channel' => 'sms'
    ]);
});

it('can verify a correct SMS OTP and update user phone', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200)
    ]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    $otp = Otp::where('identifier', '+237600000001')->first();

    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
        'code' => $otp->code
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    $this->assertDatabaseMissing('otps', ['id' => $otp->id]);
    
    $user->refresh();
    expect($user->phone)->toBe('+237600000001');
    expect($user->phone_verified_at)->not->toBeNull();
});

it('returns invalid code for wrong SMS OTP and locks after 3 attempts', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200)
    ]);

    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    for ($i = 0; $i < 2; $i++) {
        $response = $this->postJson('/api/otp/verify', [
            'channel' => 'sms',
            'identifier' => '+237600000001',
            'code' => '000000'
        ]);
        $response->assertStatus(422)->assertJson(['kind' => OtpFailure::Invalid->value]);
    }

    // 3rd failed attempt
    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
        'code' => '000000'
    ]);
    
    $response->assertStatus(429)->assertJson(['kind' => OtpFailure::Locked->value]);
});

it('returns 503 and deletes OTP if SMS sending fails', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['error' => 'API down'], 500)
    ]);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    $response->assertStatus(503)->assertJson(['message' => 'Service indisponible. Veuillez réessayer plus tard.']);
    
    // OTP should be deleted
    $this->assertDatabaseMissing('otps', [
        'identifier' => '+237600000001',
        'channel' => 'sms'
    ]);
});

// --- TESTS EMAIL ---

it('can request an OTP via Email', function () {
    \Resend\Laravel\Facades\Resend::shouldReceive('emails->send')->andReturn((object)['id' => 'mocked-id']);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'test@example.com'
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    $this->assertDatabaseHas('otps', [
        'identifier' => 'test@example.com',
        'channel' => 'email'
    ]);
});

it('fails email validation if identifier is not an email', function () {
    $response = $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'not-an-email'
    ]);

    $response->assertStatus(422);
});

it('can verify a correct Email OTP and update user email', function () {
    \Resend\Laravel\Facades\Resend::shouldReceive('emails->send')->andReturn((object)['id' => 'mocked-id']);

    $user = User::factory()->create(['email' => 'old@example.com']);
    Passport::actingAs($user);

    $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'new@example.com'
    ]);

    $otp = Otp::where('identifier', 'new@example.com')->where('channel', 'email')->first();

    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'email',
        'identifier' => 'new@example.com',
        'code' => $otp->code
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    
    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

it('returns 503 and deletes OTP if email sending fails', function () {
    \Resend\Laravel\Facades\Resend::shouldReceive('emails->send')
        ->once()
        ->andThrow(new Exception('Resend API down'));

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'fail@example.com'
    ]);

    $response->assertStatus(503)->assertJson(['message' => 'Service indisponible. Veuillez réessayer plus tard.']);
    
    // OTP should be deleted
    $this->assertDatabaseMissing('otps', [
        'identifier' => 'fail@example.com',
        'channel' => 'email'
    ]);
});

// --- SEPARATION & SECURITY ---

it('does not mix SMS and Email channels', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200)
    ]);

    // Create an SMS OTP
    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);
    $otp = Otp::where('identifier', '+237600000001')->first();
    
    // Try to verify using Email channel
    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'email',
        'identifier' => '+237600000001',
        'code' => $otp->code
    ]);
    
    // Fails because it's not an email anyway, but let's test a valid email shape
    
    \Resend\Laravel\Facades\Resend::shouldReceive('emails->send')->andReturn((object)['id' => 'mocked-id']);

    $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'test@example.com'
    ]);
    $otpEmail = Otp::where('identifier', 'test@example.com')->first();
    
    // Try to verify using SMS channel
    $response2 = $this->postJson('/api/otp/verify', [
        'channel' => 'sms',
        'identifier' => 'test@example.com',
        'code' => $otpEmail->code
    ]);

    $response2->assertStatus(422)->assertJson(['kind' => OtpFailure::Invalid->value]);
});

it('prevents requesting OTP for a number already taken by another user', function () {
    $user1 = User::factory()->create(['phone' => '+237600000001']);
    
    $user2 = User::factory()->create();
    Passport::actingAs($user2);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    $response->assertStatus(409)->assertJson(['kind' => OtpFailure::NumberTaken->value]);
});

it('can resend an OTP', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200)
    ]);

    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    $oldOtp = Otp::where('identifier', '+237600000001')->first();

    $response = $this->postJson('/api/otp/resend', [
        'channel' => 'sms',
        'identifier' => '+237600000001'
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    $newOtp = Otp::where('identifier', '+237600000001')->first();
    expect($newOtp->code)->not->toBe($oldOtp->code);
});
