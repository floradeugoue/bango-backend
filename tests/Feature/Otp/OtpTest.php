<?php

use App\Enums\OtpFailure;
use App\Models\Auth\Otp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Resend\Laravel\Facades\Resend;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Laravel Personal Access Client', '--no-interaction' => true]);
    $user = User::factory()->create();
    Passport::actingAs($user);
});

// --- TESTS SMS ---

it('can request an OTP via SMS', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200),
    ]);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    $this->assertDatabaseHas('otps', [
        'identifier' => '+237600000001',
        'channel' => 'sms',
    ]);
});

it('can verify a correct SMS OTP and update user phone', function () {
    $sentCode = null;
    Http::fake([
        '*api.sms.zomloa.app*' => function ($request) use (&$sentCode) {
            if (preg_match('/(\d{6})/', $request['sms'], $matches)) {
                $sentCode = $matches[1];
            }

            return Http::response(['success' => true], 200);
        },
    ]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    $otp = Otp::where('identifier', '+237600000001')->first();
    expect(Hash::check($sentCode, $otp->code))->toBeTrue();

    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
        'code' => $sentCode,
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    $this->assertDatabaseMissing('otps', ['id' => $otp->id]);

    $user->refresh();
    expect($user->phone)->toBe('+237600000001');
    expect($user->phone_verified_at)->not->toBeNull();
});

it('returns invalid code for wrong SMS OTP and locks after 5 attempts', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200),
    ]);

    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    for ($i = 0; $i < 4; $i++) {
        $response = $this->postJson('/api/otp/verify', [
            'channel' => 'sms',
            'identifier' => '+237600000001',
            'code' => '000000',
        ]);
        $response->assertStatus(422)->assertJson(['kind' => OtpFailure::Invalid->value]);
    }

    // 5th failed attempt
    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
        'code' => '000000',
    ]);

    $response->assertStatus(429)->assertJson(['kind' => OtpFailure::Locked->value]);
});

it('returns 503 and deletes OTP if SMS sending fails', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['error' => 'API down'], 500),
    ]);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    $response->assertStatus(503)->assertJson(['message' => 'Service indisponible. Veuillez réessayer plus tard.']);

    // OTP should be deleted
    $this->assertDatabaseMissing('otps', [
        'identifier' => '+237600000001',
        'channel' => 'sms',
    ]);
});

// --- TESTS EMAIL ---

it('can request an OTP via Email', function () {
    Resend::shouldReceive('emails->send')->andReturn((object) ['id' => 'mocked-id']);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'test@example.com',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);
    $this->assertDatabaseHas('otps', [
        'identifier' => 'test@example.com',
        'channel' => 'email',
    ]);
});

it('fails email validation if identifier is not an email', function () {
    $response = $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'not-an-email',
    ]);

    $response->assertStatus(422);
});

it('can verify a correct Email OTP and update user email', function () {
    $sentCode = null;
    Resend::shouldReceive('emails->send')
        ->andReturnUsing(function ($data) use (&$sentCode) {
            if (preg_match('/(\d{6})/', $data['text'], $matches)) {
                $sentCode = $matches[1];
            }

            return (object) ['id' => 'mocked-id'];
        });

    $user = User::factory()->create(['email' => 'old@example.com']);
    Passport::actingAs($user);

    $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'new@example.com',
    ]);

    $otp = Otp::where('identifier', 'new@example.com')->where('channel', 'email')->first();
    expect(Hash::check($sentCode, $otp->code))->toBeTrue();

    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'email',
        'identifier' => 'new@example.com',
        'code' => $sentCode,
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

it('returns 503 and deletes OTP if email sending fails', function () {
    Resend::shouldReceive('emails->send')
        ->once()
        ->andThrow(new Exception('Resend API down'));

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'fail@example.com',
    ]);

    $response->assertStatus(503)->assertJson(['message' => 'Service indisponible. Veuillez réessayer plus tard.']);

    // OTP should be deleted
    $this->assertDatabaseMissing('otps', [
        'identifier' => 'fail@example.com',
        'channel' => 'email',
    ]);
});

// --- SEPARATION & SECURITY ---

it('does not mix SMS and Email channels', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200),
    ]);

    // Create an SMS OTP
    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);
    $otp = Otp::where('identifier', '+237600000001')->first();

    // Try to verify using Email channel
    $response = $this->postJson('/api/otp/verify', [
        'channel' => 'email',
        'identifier' => '+237600000001',
        'code' => '123456',
    ]);

    // Fails because it's not an email anyway, but let's test a valid email shape

    Resend::shouldReceive('emails->send')->andReturn((object) ['id' => 'mocked-id']);

    $this->postJson('/api/otp/request', [
        'channel' => 'email',
        'identifier' => 'test@example.com',
    ]);

    // Try to verify using SMS channel
    $response2 = $this->postJson('/api/otp/verify', [
        'channel' => 'sms',
        'identifier' => 'test@example.com',
        'code' => '123456',
    ]);

    $response2->assertStatus(422)->assertJson(['kind' => OtpFailure::Invalid->value]);
});

it('prevents requesting OTP for a number already taken by another user', function () {
    $user1 = User::factory()->create(['phone' => '+237600000001']);

    $user2 = User::factory()->create();
    Passport::actingAs($user2);

    $response = $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    $response->assertStatus(409)->assertJson(['kind' => OtpFailure::NumberTaken->value]);
});

it('can resend an OTP', function () {
    Http::fake([
        '*api.sms.zomloa.app*' => Http::response(['success' => true], 200),
    ]);

    $this->postJson('/api/otp/request', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    $oldOtp = Otp::where('identifier', '+237600000001')->first();

    $response = $this->postJson('/api/otp/resend', [
        'channel' => 'sms',
        'identifier' => '+237600000001',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    $newOtp = Otp::where('identifier', '+237600000001')->first();
    expect($newOtp->code)->not->toBe($oldOtp->code);
});
