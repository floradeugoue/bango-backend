<?php

use App\Models\User;
use App\Services\Profile\ContactMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    RateLimiter::clear('contact_matching');
});

test('it requires authentication to match contacts', function () {
    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => ['+221771234567'],
    ]);

    $response->assertUnauthorized();
});

test('it matches active users by plain phone number or last 8 digits', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create([
        'status' => 'active',
        'phone' => '+221771234567',
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'is_verified' => true,
    ]);

    Passport::actingAs($me);

    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => ['+221771234567', '+221709999999'],
    ]);

    $response->assertOk()
        ->assertJsonPath('state', 'matched')
        ->assertJsonPath('scanned', 2)
        ->assertJsonPath('matched_count', 1);

    $suggestions = $response->json('suggestions');
    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['id'])->toBe((string) $friend->id)
        ->and($suggestions[0]['name'])->toBe($friend->name)
        ->and($suggestions[0]['handle'])->toBe($friend->handle)
        ->and($suggestions[0]['from_contacts'])->toBeTrue()
        ->and($suggestions[0]['is_verified'])->toBeTrue();

    // Verify privacy: phone number and email are NEVER leaked in payload
    expect(array_key_exists('phone', $suggestions[0]))->toBeFalse()
        ->and(array_key_exists('email', $suggestions[0]))->toBeFalse();
});

test('it matches users via sha256 fingerprints and retires legacy js hash by default', function () {
    $me = User::factory()->create();
    $user1 = User::factory()->create([
        'status' => 'active',
        'phone' => '+221775551234',
    ]);
    $user2 = User::factory()->create([
        'status' => 'active',
        'phone' => '+237699112233',
    ]);

    $digits1 = substr(preg_replace('/\D/', '', '+221775551234'), -8);
    $sha256Hash1 = hash('sha256', $digits1);

    $digits2 = substr(preg_replace('/\D/', '', '+237699112233'), -8);
    $sha256Hash2 = hash('sha256', $digits2);
    $legacyJsHash2 = ContactMatchingService::computeLegacyJsHash($digits2);

    Passport::actingAs($me);

    // 1. Avec la configuration par défaut (enable_legacy_hash = false) : SHA-256 match, legacy hash ne match pas
    $response = $this->postJson('/api/friends/match-fingerprints', [
        'fingerprints' => [
            $sha256Hash1,
            $sha256Hash2,
            'unknown_hash_1234567890abcdef',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('state', 'matched')
        ->assertJsonPath('scanned', 3)
        ->assertJsonPath('matched_count', 2);

    $ids = collect($response->json('suggestions'))->pluck('id')->all();
    expect($ids)->toContain((string) $user1->id)
        ->and($ids)->toContain((string) $user2->id);

    // Si on n'envoie que le legacyJsHash2 sans sha256, il n'est plus reconnu par défaut
    RateLimiter::clear('contact_matching:daily:'.$me->id);
    $legacyResponse = $this->postJson('/api/friends/match-fingerprints', [
        'fingerprints' => [$legacyJsHash2],
    ]);
    expect($legacyResponse->json('matched_count'))->toBe(0);

    // Mais si activé temporairement par configuration
    config(['services.contact_matching.enable_legacy_hash' => true]);
    RateLimiter::clear('contact_matching:daily:'.$me->id);
    $legacyActiveResponse = $this->postJson('/api/friends/match-fingerprints', [
        'fingerprints' => [$legacyJsHash2],
    ]);
    expect($legacyActiveResponse->json('matched_count'))->toBe(1);
    config(['services.contact_matching.enable_legacy_hash' => false]);
});

test('it never suggests the current user to themselves', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'phone' => '+221770001122',
    ]);

    Passport::actingAs($me);

    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => ['+221770001122'],
    ]);

    $response->assertOk()
        ->assertJsonPath('matched_count', 0)
        ->assertJsonPath('suggestions', []);
});

test('it excludes soft-deleted, inactive and suspended users', function () {
    $me = User::factory()->create();

    $deletedUser = User::factory()->create([
        'status' => 'active',
        'phone' => '+221771110001',
        'deleted_at' => now(),
    ]);

    $inactiveUser = User::factory()->create([
        'status' => 'pending',
        'phone' => '+221771110002',
    ]);

    $suspendedUser = User::factory()->create([
        'status' => 'active',
        'phone' => '+221771110003',
        'suspended_until' => now()->addDays(7),
    ]);

    Passport::actingAs($me);

    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => [
            '+221771110001',
            '+221771110002',
            '+221771110003',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('matched_count', 0)
        ->assertJsonPath('suggestions', []);
});

test('it strictly respects mutual blocks', function () {
    $me = User::factory()->create();

    $blockedByMe = User::factory()->create([
        'status' => 'active',
        'phone' => '+221772220001',
    ]);
    DB::table('user_blocks')->insert([
        'blocker_id' => $me->id,
        'blocked_id' => $blockedByMe->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $whoBlockedMe = User::factory()->create([
        'status' => 'active',
        'phone' => '+221772220002',
    ]);
    DB::table('user_blocks')->insert([
        'blocker_id' => $whoBlockedMe->id,
        'blocked_id' => $me->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $normalFriend = User::factory()->create([
        'status' => 'active',
        'phone' => '+221772220003',
    ]);

    Passport::actingAs($me);

    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => [
            '+221772220001',
            '+221772220002',
            '+221772220003',
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('matched_count', 1);

    $suggestions = $response->json('suggestions');
    expect($suggestions[0]['id'])->toBe((string) $normalFriend->id);
});

test('it rejects batches exceeding 2000 items with 422', function () {
    $me = User::factory()->create();
    Passport::actingAs($me);

    $excessive = array_map(fn ($i) => '+22177000'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, 2001));

    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => $excessive,
    ]);

    $response->assertStatus(422);
});

test('it rate-limits requests exceeding 3 per day with 429', function () {
    $me = User::factory()->create();
    Passport::actingAs($me);

    $rateKey = 'contact_matching:daily:'.$me->id;
    RateLimiter::clear($rateKey);

    // Hit 3 times successfully
    for ($i = 0; $i < 3; $i++) {
        $response = $this->postJson('/api/friends/match-fingerprints', [
            'phones' => ['+221779998877'],
        ]);
        $response->assertOk();
    }

    // 4th time must be rate-limited (429)
    $response = $this->postJson('/api/friends/match-fingerprints', [
        'phones' => ['+221779998877'],
    ]);

    $response->assertStatus(429)
        ->assertJsonPath('kind', 'rate_limited');
});
