<?php

use App\Models\Auth\Otp;
use App\Models\User;
use App\Notifications\Auth\WelcomeEmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);
});

it('sends a welcome email verification notification upon signup without blocking access', function () {
    Notification::fake();

    $response = postJson('/api/auth/signup', [
        'email' => 'newuser@bango.africa',
        'password' => 'Password123!',
        'handle' => 'newuser',
        'display_name' => 'New User',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'token',
            'user' => [
                'id',
                'email',
                'email_verified',
                'emailVerified',
            ],
        ])
        ->assertJson([
            'user' => [
                'email' => 'newuser@bango.africa',
                'email_verified' => false,
                'emailVerified' => false,
            ],
        ]);

    $user = User::where('email', 'newuser@bango.africa')->firstOrFail();
    expect($user->email_verified_at)->toBeNull();

    Notification::assertSentTo($user, WelcomeEmailVerificationNotification::class, function ($notification) {
        return ! empty($notification->code);
    });

    // Check that OTP code was created
    $this->assertDatabaseHas('otps', [
        'identifier' => 'newuser@bango.africa',
        'channel' => 'email',
        'context' => 'email_verification',
    ]);
});

it('can verify email using the signed temporary link', function () {
    $user = User::factory()->create([
        'email' => 'signed@bango.africa',
        'email_verified_at' => null,
    ]);

    $signedUrl = URL::temporarySignedRoute(
        'api.auth.email.verify.link',
        now()->addHours(24),
        [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]
    );

    $response = getJson($signedUrl);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'email_verified' => true,
        ]);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('rejects an invalid or tampered signature on verification link', function () {
    $user = User::factory()->create([
        'email' => 'tampered@bango.africa',
        'email_verified_at' => null,
    ]);

    $tamperedUrl = URL::temporarySignedRoute(
        'api.auth.email.verify.link',
        now()->addHours(24),
        [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]
    ).'&tampered=1';

    $response = getJson($tamperedUrl);
    $response->assertStatus(403);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeFalse();
});

it('can verify email using the 6-digit OTP code', function () {
    $user = User::factory()->create([
        'email' => 'otp@bango.africa',
        'email_verified_at' => null,
    ]);

    $code = '739201';
    Otp::create([
        'identifier' => $user->email,
        'code' => $code,
        'channel' => 'email',
        'context' => 'email_verification',
        'attempts' => 0,
        'expires_at' => now()->addHours(24),
    ]);

    $response = postJson('/api/auth/email/verify', [
        'email' => $user->email,
        'code' => $code,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'email_verified' => true,
        ]);

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue();
    $this->assertDatabaseMissing('otps', [
        'identifier' => $user->email,
        'context' => 'email_verification',
    ]);
});

it('rejects an incorrect OTP code and locks after maximum attempts', function () {
    $user = User::factory()->create([
        'email' => 'wrongotp@bango.africa',
        'email_verified_at' => null,
    ]);

    Otp::create([
        'identifier' => $user->email,
        'code' => '123456',
        'channel' => 'email',
        'context' => 'email_verification',
        'attempts' => 4,
        'expires_at' => now()->addHours(24),
    ]);

    $response = postJson('/api/auth/email/verify', [
        'email' => $user->email,
        'code' => '999999',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    $otp = Otp::where('identifier', $user->email)->where('context', 'email_verification')->first();
    expect($otp->attempts)->toBe(5)
        ->and($otp->locked_until)->not->toBeNull();
});

it('reflects email_verified state in auth me endpoint', function () {
    $user = User::factory()->create([
        'email' => 'me_test@bango.africa',
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user, 'api')->getJson('/api/auth/me');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'email' => 'me_test@bango.africa',
                'email_verified' => false,
                'emailVerified' => false,
            ],
        ]);

    $user->markEmailAsVerified();

    $responseVerified = $this->actingAs($user, 'api')->getJson('/api/auth/me');

    $responseVerified->assertStatus(200)
        ->assertJson([
            'data' => [
                'email' => 'me_test@bango.africa',
                'email_verified' => true,
                'emailVerified' => true,
            ],
        ]);
});

it('can resend the confirmation email when requested', function () {
    Notification::fake();

    $user = User::factory()->create([
        'email' => 'resend@bango.africa',
        'email_verified_at' => null,
    ]);

    $response = $this->actingAs($user, 'api')->postJson('/api/auth/email/resend');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);

    Notification::assertSentTo($user, WelcomeEmailVerificationNotification::class);
});
