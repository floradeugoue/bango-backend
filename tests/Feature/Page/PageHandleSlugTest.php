<?php

use App\Enums\Page\PageSector;
use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

function pagePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Belle Tresse',
        'sector' => PageSector::Services->value,
    ], $overrides);
}

test('un handle déjà pris sous sa forme slug est refusé en 422 sans création', function () {
    $this->postJson('/api/pages', pagePayload(['handle' => 'belletresse-bonamoussadi']))->assertCreated();

    $this->postJson('/api/pages', pagePayload(['handle' => 'belletresse_bonamoussadi']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('handle')
        ->assertJsonPath('errors.handle.0', 'handle taken');

    expect(Page::where('handle', 'like', 'belletresse%')->count())->toBe(1);
});

test('un handle libre est enregistré sous sa forme slug', function () {
    $this->postJson('/api/pages', pagePayload(['handle' => 'Belle_Tresse']))
        ->assertCreated()
        ->assertJsonPath('data.handle', 'belle-tresse');
});

test('sans handle, le identifiant dérivé du nom reçoit un suffixe en cas de collision', function () {
    $this->postJson('/api/pages', pagePayload())->assertCreated()->assertJsonPath('data.handle', 'belle-tresse');
    $this->postJson('/api/pages', pagePayload())->assertCreated()->assertJsonPath('data.handle', 'belle-tresse-1');
});

test('la mise à jour refuse un handle pris sous sa forme slug', function () {
    $this->postJson('/api/pages', pagePayload(['handle' => 'cabinet-notarial']))->assertCreated();
    $page = Page::where('handle', '!=', 'cabinet-notarial')->first()
        ?? Page::findOrFail($this->postJson('/api/pages', pagePayload(['name' => 'Autre', 'handle' => 'autre']))->json('data.id'));

    $this->putJson("/api/pages/{$page->id}", ['handle' => 'cabinet_notarial'])
        ->assertStatus(422)
        ->assertJsonPath('errors.handle.0', 'handle taken');
});
