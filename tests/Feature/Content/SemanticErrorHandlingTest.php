<?php

use App\Exceptions\Content\ResourceGoneException;
use App\Exceptions\Content\ResourceLockedException;
use App\Exceptions\Content\ResourceNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('/api/test-semantic-errors/{type}', function (string $type) {
        return match ($type) {
            'not-found' => throw new ResourceNotFoundException('Élément introuvable.', 'CUSTOM_NOT_FOUND', 'post', '999'),
            'gone' => throw new ResourceGoneException('Contenu retiré par son auteur.', 'CUSTOM_GONE', 'reel', 'reel-uuid-1'),
            'locked' => throw new ResourceLockedException('Contenu gelé pour vérification.', 'CUSTOM_LOCKED', 'listing', 'listing-uuid-2'),
            default => response()->json(['status' => 'ok']),
        };
    });
});

test('resource not found exception renders semantic 404 json response', function () {
    $response = $this->getJson('/api/test-semantic-errors/not-found');

    $response->assertStatus(404)
        ->assertJsonPath('error', 'CUSTOM_NOT_FOUND')
        ->assertJsonPath('message', 'Élément introuvable.')
        ->assertJsonPath('resource_type', 'post')
        ->assertJsonPath('resource_id', '999');
});

test('resource gone exception renders semantic 410 json response', function () {
    $response = $this->getJson('/api/test-semantic-errors/gone');

    $response->assertStatus(410)
        ->assertJsonPath('error', 'CUSTOM_GONE')
        ->assertJsonPath('message', 'Contenu retiré par son auteur.')
        ->assertJsonPath('resource_type', 'reel')
        ->assertJsonPath('resource_id', 'reel-uuid-1');
});

test('resource locked exception renders semantic 423 json response', function () {
    $response = $this->getJson('/api/test-semantic-errors/locked');

    $response->assertStatus(423)
        ->assertJsonPath('error', 'CUSTOM_LOCKED')
        ->assertJsonPath('message', 'Contenu gelé pour vérification.')
        ->assertJsonPath('resource_type', 'listing')
        ->assertJsonPath('resource_id', 'listing-uuid-2');
});
