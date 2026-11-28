<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    RateLimiter::clear('signup_phone:rate:237690000000');
    RateLimiter::clear('signup_phone:ip:127.0.0.1');
});

it('starts phone signup and returns a 600s challenge', function () {
    $response = postJson('/api/auth/signup/phone/start', [
        'phone' => '+237690000000',
        'channel' => 'whatsapp',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['challenge', 'expires_in'])
        ->assertJsonPath('expires_in', 600);

    $challenge = $response->json('challenge');
    expect(Cache::has('signup_challenge:'.$challenge))->toBeTrue();
});

it('rejects phone signup if phone number is already registered with 409 phone-taken', function () {
    User::factory()->create([
        'phone' => '+237690000000',
    ]);

    $response = postJson('/api/auth/signup/phone/start', [
        'phone' => '+237690000000',
        'channel' => 'whatsapp',
    ]);

    $response->assertStatus(409)
        ->assertJsonPath('kind', 'phone-taken');
});

it('rate limits phone signup requests to 3 per hour with 429', function () {
    for ($i = 0; $i < 3; $i++) {
        postJson('/api/auth/signup/phone/start', [
            'phone' => '+237690000000',
            'channel' => 'whatsapp',
        ])->assertOk();
    }

    $response = postJson('/api/auth/signup/phone/start', [
        'phone' => '+237690000000',
        'channel' => 'whatsapp',
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('kind', 'rate_limited')
        ->assertJsonStructure(['kind', 'retry_after']);
});

it('rejects invalid phone number formats with 422', function () {
    $response = postJson('/api/auth/signup/phone/start', [
        'phone' => '123',
    ]);

    $response->assertStatus(422);
});

it('rejects verification if challenge is expired or invalid with 422 expired', function () {
    $response = postJson('/api/auth/signup/phone/verify', [
        'challenge' => 'non-existent-challenge',
        'code' => '123456',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'expired');
});

it('rejects verification with wrong code and decrements attemptsLeft', function () {
    $challenge = 'test-challenge-attempts';
    Cache::put('signup_challenge:'.$challenge, [
        'phone' => '+237690000000',
        'code_hash' => Hash::make('123456'),
        'attempts_left' => 5,
        'expires_at' => now()->addMinutes(10)->timestamp,
    ], 600);

    $response = postJson('/api/auth/signup/phone/verify', [
        'challenge' => $challenge,
        'code' => '999999',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'invalid')
        ->assertJsonPath('attemptsLeft', 4);

    $updated = Cache::get('signup_challenge:'.$challenge);
    expect($updated['attempts_left'])->toBe(4);
});

it('invalidates challenge when 5 attempts are exhausted', function () {
    $challenge = 'test-challenge-exhausted';
    Cache::put('signup_challenge:'.$challenge, [
        'phone' => '+237690000000',
        'code_hash' => Hash::make('123456'),
        'attempts_left' => 1,
        'expires_at' => now()->addMinutes(10)->timestamp,
    ], 600);

    $response = postJson('/api/auth/signup/phone/verify', [
        'challenge' => $challenge,
        'code' => '000000',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'invalid')
        ->assertJsonPath('attemptsLeft', 0);

    expect(Cache::has('signup_challenge:'.$challenge))->toBeFalse();
});

it('verifies code successfully and creates user with passport token and verified phone', function () {
    $challenge = 'test-challenge-success';
    Cache::put('signup_challenge:'.$challenge, [
        'phone' => '+237690000000',
        'code_hash' => Hash::make('654321'),
        'attempts_left' => 5,
        'expires_at' => now()->addMinutes(10)->timestamp,
    ], 600);

    $response = postJson('/api/auth/signup/phone/verify', [
        'challenge' => $challenge,
        'code' => '654321',
        'device_name' => 'Test Phone',
        'persistent' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('created', true)
        ->assertJsonPath('user.phone', '+237690000000')
        ->assertJsonPath('user.phone_verified', true)
        ->assertJsonStructure(['token', 'user' => ['id', 'phone', 'handle', 'phone_verified'], 'created']);

    expect(Cache::has('signup_challenge:'.$challenge))->toBeFalse();

    $this->assertDatabaseHas('users', [
        'phone' => '+237690000000',
    ]);

    $user = User::where('phone', '+237690000000')->first();
    expect($user)->not->toBeNull()
        ->and($user->phone_verified_at)->not->toBeNull();
});

it('cannot reuse the same challenge once verified', function () {
    $challenge = 'test-challenge-onetime';
    Cache::put('signup_challenge:'.$challenge, [
        'phone' => '+237691111111',
        'code_hash' => Hash::make('654321'),
        'attempts_left' => 5,
        'expires_at' => now()->addMinutes(10)->timestamp,
    ], 600);

    postJson('/api/auth/signup/phone/verify', [
        'challenge' => $challenge,
        'code' => '654321',
    ])->assertStatus(201);

    // Second attempt should fail with expired
    $response = postJson('/api/auth/signup/phone/verify', [
        'challenge' => $challenge,
        'code' => '654321',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'expired');
});
