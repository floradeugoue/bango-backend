<?php

use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('it requires authentication to access 2fa totp setup', function () {
    $this->postJson('/api/settings/2fa/totp/setup')
        ->assertUnauthorized();
});

test('it sets up totp secret and otpauth uri', function () {
    $user = User::factory()->create([
        'email' => 'kofi.annan@bango.africa',
    ]);
    Passport::actingAs($user);

    $response = $this->postJson('/api/settings/2fa/totp/setup');

    $response->assertOk()
        ->assertJsonStructure([
            'secret',
            'uri',
            'issuer',
            'account',
        ]);

    $secret = $response->json('secret');
    $uri = $response->json('uri');

    expect($secret)->toBeString()
        ->and(strlen($secret))->toBe(32) // 20 bytes in Base32 = 32 chars
        ->and($response->json('issuer'))->toBe('BANGO')
        ->and($response->json('account'))->toBe('kofi.annan@bango.africa')
        ->and($uri)->toContain('otpauth://totp/BANGO:')
        ->and($uri)->toContain('secret='.$secret);

    // Verify pending secret is cached
    expect(Cache::get("2fa_pending_secret:{$user->id}"))->toBe($secret);
});

test('it rejects totp verification without prior setup', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = $this->postJson('/api/settings/2fa/totp/verify', [
        'code' => '123456',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'no-pending-setup');
});

test('it rejects totp verification with invalid code', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();
    Cache::put("2fa_pending_secret:{$user->id}", $secret, now()->addMinutes(15));

    $response = $this->postJson('/api/settings/2fa/totp/verify', [
        'code' => '000000',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('kind', 'invalid-code');
});

test('it activates totp with valid code, encrypts secret and generates initial backup codes', function () {
    $user = User::factory()->create([
        'email' => 'fatou@bango.africa',
    ]);
    Passport::actingAs($user);

    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();
    Cache::put("2fa_pending_secret:{$user->id}", $secret, now()->addMinutes(15));

    $currentStep = (int) floor(time() / 30);
    $validCode = $totpService->calculateTotpForStep($secret, $currentStep);

    $response = $this->postJson('/api/settings/2fa/totp/verify', [
        'code' => $validCode,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('two_factor_method', 'app')
        ->assertJsonPath('remaining_backup_codes', 8);

    $backupCodes = $response->json('backup_codes');
    expect($backupCodes)->toBeArray()
        ->and($backupCodes)->toHaveCount(8);

    $user->refresh();
    expect($user->two_factor_method)->toBe('app');

    // Verify secret is encrypted at rest (not stored in plaintext!)
    expect($user->two_factor_secret)->not->toBe($secret);
    expect(Crypt::decryptString($user->two_factor_secret))->toBe($secret);

    // Verify backup codes stored in DB are hashed (never plaintext!)
    foreach ($user->backup_codes as $hashedCode) {
        expect($backupCodes)->not->toContain($hashedCode);
    }

    // Verify pending secret cache is cleared
    expect(Cache::has("2fa_pending_secret:{$user->id}"))->toBeFalse();
});

test('it prevents replay attack using the same totp code in the same window', function () {
    $user = User::factory()->create();
    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();

    $user->two_factor_method = 'app';
    $user->two_factor_secret = Crypt::encryptString($secret);
    $user->save();

    $currentStep = (int) floor(time() / 30);
    $validCode = $totpService->calculateTotpForStep($secret, $currentStep);

    // First verification passes
    $firstTry = $totpService->verifyUser($user, $validCode);
    expect($firstTry)->toBeTrue();

    // Immediate replay of the same code must fail
    $secondTry = $totpService->verifyUser($user, $validCode);
    expect($secondTry)->toBeFalse();
});

test('it verifies totp code during second-factor signin flow', function () {
    $totpService = app(TotpService::class);
    $secret = $totpService->generateSecret();

    $user = User::factory()->create([
        'two_factor_method' => 'app',
        'two_factor_secret' => Crypt::encryptString($secret),
    ]);

    $challengeId = 'test-challenge-'.uniqid();
    Cache::put('2fa.challenge.'.$challengeId, [
        'user_id' => $user->id,
        'channel' => 'app',
        'persistent' => false,
    ], now()->addMinutes(5));

    $currentStep = (int) floor(time() / 30);
    $validCode = $totpService->calculateTotpForStep($secret, $currentStep);

    $response = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $validCode,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.id', $user->id);
});

test('it disables 2fa with valid password or confirmation code', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Secret123!'),
        'two_factor_method' => 'app',
        'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'backup_codes' => ['hashed_code_1', 'hashed_code_2'],
    ]);
    Passport::actingAs($user);

    // Fails with bad password
    $this->postJson('/api/settings/2fa/disable', [
        'password' => 'WrongPassword',
    ])->assertStatus(403);

    // Succeeds with correct password
    $response = $this->postJson('/api/settings/2fa/disable', [
        'password' => 'Secret123!',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('two_factor_method', 'off');

    $user->refresh();
    expect($user->two_factor_method)->toBe('off')
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->backup_codes)->toBeEmpty();
});
