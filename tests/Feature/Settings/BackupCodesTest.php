<?php

use App\Models\User;
use App\Services\Auth\BackupCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('it requires authentication to generate or list backup codes', function () {
    $this->getJson('/api/settings/2fa/backup-codes')->assertUnauthorized();
    $this->postJson('/api/settings/2fa/backup-codes')->assertUnauthorized();
});

test('it generates exactly 8 valid format backup codes and stores only hashes in database', function () {
    $user = User::factory()->create([
        'two_factor_method' => 'app',
    ]);
    Passport::actingAs($user);

    $response = $this->postJson('/api/settings/2fa/backup-codes');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('count', 8)
        ->assertJsonPath('remaining', 8);

    $plainCodes = $response->json('backup_codes');
    expect($plainCodes)->toBeArray()
        ->and($plainCodes)->toHaveCount(8);

    $alphabet = BackupCodeService::ALPHABET;

    foreach ($plainCodes as $code) {
        // Format XXXX-XXXX
        expect(preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code))->toBe(1);

        // Ensure confusing characters (I, O, 0, 1) are never present
        expect(str_contains($code, 'I'))->toBeFalse()
            ->and(str_contains($code, 'O'))->toBeFalse()
            ->and(str_contains($code, '0'))->toBeFalse()
            ->and(str_contains($code, '1'))->toBeFalse();
    }

    $user->refresh();
    expect($user->backup_codes)->toHaveCount(8);

    // Ensure database contains SHA-256 hashes, not plaintext!
    foreach ($user->backup_codes as $storedEntry) {
        expect($plainCodes)->not->toContain($storedEntry);
        expect(strlen($storedEntry))->toBe(64); // SHA-256 hex length
    }
});

test('it single-uses a backup code during login and decrements remaining count atomically', function () {
    $backupCodeService = app(BackupCodeService::class);
    $result = $backupCodeService->generateCodes();

    $user = User::factory()->create([
        'two_factor_method' => 'app',
        'backup_codes' => $result['hashed'],
    ]);

    $firstCode = $result['plain'][0];

    $challengeId = 'test-challenge-'.uniqid();
    Cache::put('2fa.challenge.'.$challengeId, [
        'user_id' => $user->id,
        'channel' => 'app',
        'persistent' => false,
    ], now()->addMinutes(5));

    // Sign in with backup code (first time: must succeed)
    $response = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $firstCode,
    ]);

    $response->assertOk()
        ->assertJsonPath('backup_code_used', true)
        ->assertJsonPath('remaining_backup_codes', 7);

    // Verify code was consumed in database
    $user->refresh();
    expect($user->backup_codes)->toHaveCount(7);
    expect($user->backup_codes)->not->toContain(hash('sha256', $firstCode));

    // Try signing in again with the same used code (must fail!)
    $secondChallengeId = 'test-challenge-'.uniqid();
    Cache::put('2fa.challenge.'.$secondChallengeId, [
        'user_id' => $user->id,
        'channel' => 'app',
        'persistent' => false,
    ], now()->addMinutes(5));

    $failResponse = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $secondChallengeId,
        'code' => $firstCode,
    ]);

    $failResponse->assertStatus(422)
        ->assertJsonPath('kind', 'invalid-code');
});

test('it accepts backup code without hyphen during login', function () {
    $backupCodeService = app(BackupCodeService::class);
    $result = $backupCodeService->generateCodes();

    $user = User::factory()->create([
        'two_factor_method' => 'app',
        'backup_codes' => $result['hashed'],
    ]);

    $formattedCode = $result['plain'][0];
    $codeWithoutHyphen = str_replace('-', '', $formattedCode);

    $challengeId = 'test-challenge-'.uniqid();
    Cache::put('2fa.challenge.'.$challengeId, [
        'user_id' => $user->id,
        'channel' => 'app',
        'persistent' => false,
    ], now()->addMinutes(5));

    $response = $this->postJson('/api/auth/signin/second-factor', [
        'challenge' => $challengeId,
        'code' => $codeWithoutHyphen,
    ]);

    $response->assertOk()
        ->assertJsonPath('backup_code_used', true)
        ->assertJsonPath('remaining_backup_codes', 7);
});

test('it regenerates backup codes and invalidates all previous codes immediately', function () {
    $backupCodeService = app(BackupCodeService::class);
    $initialResult = $backupCodeService->generateCodes();

    $user = User::factory()->create([
        'two_factor_method' => 'app',
        'backup_codes' => $initialResult['hashed'],
    ]);
    Passport::actingAs($user);

    $firstOldCode = $initialResult['plain'][0];

    // Regenerate
    $regenResponse = $this->postJson('/api/settings/2fa/backup-codes/regenerate');

    $regenResponse->assertOk()
        ->assertJsonPath('count', 8)
        ->assertJsonPath('remaining', 8);

    $newCodes = $regenResponse->json('backup_codes');
    expect($newCodes)->toHaveCount(8);

    // Old codes must no longer exist or be usable
    $user->refresh();
    expect($user->backup_codes)->toHaveCount(8);
    expect($user->backup_codes)->not->toContain(hash('sha256', $firstOldCode));

    // Attempting to consume old code must fail
    expect($backupCodeService->verifyAndConsume($user, $firstOldCode))->toBeFalse();
});

test('it reports backup code status without leaking codes', function () {
    $user = User::factory()->create([
        'backup_codes' => [
            hash('sha256', 'TEST-1234'),
            hash('sha256', 'TEST-5678'),
            hash('sha256', 'TEST-9999'),
        ],
    ]);
    Passport::actingAs($user);

    $response = $this->getJson('/api/settings/2fa/backup-codes');

    $response->assertOk()
        ->assertJson([
            'has_backup_codes' => true,
            'count' => 3,
            'remaining' => 3,
        ]);

    expect($response->json('backup_codes'))->toBeNull();
});
