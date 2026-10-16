<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it suggests 4 to 5 available handles from first and last name', function () {
    $response = $this->postJson('/api/profile/handle-suggestions', [
        'first_name' => 'Kofi',
        'last_name' => 'Annan',
        'city' => 'Dakar',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data']);

    $suggestions = $response->json('data');

    expect($suggestions)->toBeArray()
        ->and(count($suggestions))->toBeGreaterThanOrEqual(4)
        ->and(count($suggestions))->toBeLessThanOrEqual(5)
        ->and($suggestions)->toContain('kofi.annan')
        ->and($suggestions)->toContain('kofi.dakar');

    // Verify all suggestions are strictly formatted and not taken
    foreach ($suggestions as $handle) {
        expect(strlen($handle))->toBeGreaterThanOrEqual(3)
            ->and(strlen($handle))->toBeLessThanOrEqual(20)
            ->and(preg_match('/^[a-z0-9.]+$/', $handle))->toBe(1)
            ->and(str_starts_with($handle, '.'))->toBeFalse()
            ->and(str_ends_with($handle, '.'))->toBeFalse();

        expect(User::where('handle', $handle)->exists())->toBeFalse();
    }
});

test('it normalizes accented characters and strips invalid symbols', function () {
    $response = $this->postJson('/api/profile/handle-suggestions', [
        'first_name' => 'Éléonore',
        'last_name' => 'François-Xavier',
        'city' => 'Yaoundé',
    ]);

    $response->assertOk();

    $suggestions = $response->json('data');
    expect($suggestions)->toBeArray()
        ->and(count($suggestions))->toBeGreaterThanOrEqual(4);

    foreach ($suggestions as $handle) {
        expect(preg_match('/^[a-z0-9.]+$/', $handle))->toBe(1)
            ->and($handle)->not->toContain('é')
            ->and($handle)->not->toContain('ç')
            ->and($handle)->not->toContain('-');
    }
});

test('it excludes handles already taken by existing users and generates free variants', function () {
    // kofi.annan is already taken in the database
    User::factory()->create(['handle' => 'kofi.annan']);
    User::factory()->create(['handle' => 'kofiannan']);

    $response = $this->postJson('/api/profile/handle-suggestions', [
        'first_name' => 'Kofi',
        'last_name' => 'Annan',
    ]);

    $response->assertOk();

    $suggestions = $response->json('data');

    expect($suggestions)->not->toContain('kofi.annan')
        ->and($suggestions)->not->toContain('kofiannan')
        ->and(count($suggestions))->toBeGreaterThanOrEqual(4);

    foreach ($suggestions as $handle) {
        expect(User::where('handle', $handle)->exists())->toBeFalse();
    }
});

test('it never suggests reserved words', function () {
    $response = $this->postJson('/api/profile/handle-suggestions', [
        'first_name' => 'Admin',
        'last_name' => 'Bango',
    ]);

    $response->assertOk();

    $suggestions = $response->json('data');

    foreach ($suggestions as $handle) {
        expect($handle)->not->toBe('admin')
            ->and($handle)->not->toBe('bango')
            ->and($handle)->not->toBe('support')
            ->and(str_starts_with($handle, 'admin'))->toBeFalse()
            ->and(str_starts_with($handle, 'bango'))->toBeFalse();
    }
});

test('it handles very short or missing names gracefully', function () {
    $response = $this->postJson('/api/profile/handle-suggestions', [
        'first_name' => 'a',
        'last_name' => 'b',
    ]);

    $response->assertOk();

    $suggestions = $response->json('data');

    expect($suggestions)->toBeArray()
        ->and(count($suggestions))->toBeGreaterThanOrEqual(4);

    foreach ($suggestions as $handle) {
        expect(strlen($handle))->toBeGreaterThanOrEqual(3)
            ->and(strlen($handle))->toBeLessThanOrEqual(20);
    }
});

test('it handles display_name parameter when first and last are not separate', function () {
    $response = $this->postJson('/api/profile/handle-suggestions', [
        'display_name' => 'Amadou Diallo',
        'city' => 'Conakry',
    ]);

    $response->assertOk();

    $suggestions = $response->json('data');

    expect($suggestions)->toContain('amadou.diallo')
        ->and(count($suggestions))->toBeGreaterThanOrEqual(4);
});
