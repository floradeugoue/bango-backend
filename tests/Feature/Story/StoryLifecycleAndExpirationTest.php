<?php

use App\Enums\Story\StoryStatus;
use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

test('la commande stories:expire archive les Stories expirées autorisant l archivage', function () {
    $activeStory = Story::factory()->create([
        'user_id' => $this->user->id,
        'status' => StoryStatus::Active,
        'allow_archive' => true,
        'expires_at' => now()->addHours(10), // Pas encore expirée
    ]);

    $expiredArchivable = Story::factory()->create([
        'user_id' => $this->user->id,
        'status' => StoryStatus::Active,
        'allow_archive' => true,
        'expires_at' => now()->subMinute(), // Expirée
    ]);

    $expiredNonArchivable = Story::factory()->create([
        'user_id' => $this->user->id,
        'status' => StoryStatus::Active,
        'allow_archive' => false,
        'expires_at' => now()->subMinute(), // Expirée sans archive
    ]);

    // Exécution de la commande
    Artisan::call('stories:expire');

    expect($activeStory->fresh()->status)->toBe(StoryStatus::Active)
        ->and($expiredArchivable->fresh()->status)->toBe(StoryStatus::Archived)
        ->and($expiredNonArchivable->fresh()->status)->toBe(StoryStatus::Expired);
});

test('l utilisateur peut récupérer l ensemble de ses Stories archivées', function () {
    $archivedStory1 = Story::factory()->archived()->create(['user_id' => $this->user->id]);
    $archivedStory2 = Story::factory()->archived()->create(['user_id' => $this->user->id]);
    $activeStory = Story::factory()->create(['user_id' => $this->user->id]);

    StorySlide::factory()->create(['story_id' => $archivedStory1->id]);
    StorySlide::factory()->create(['story_id' => $archivedStory2->id]);
    StorySlide::factory()->create(['story_id' => $activeStory->id]);

    $response = $this->getJson('/api/stories/archived');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

test('un utilisateur peut supprimer définitivement sa Story', function () {
    $story = Story::factory()->create(['user_id' => $this->user->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $response = $this->deleteJson("/api/stories/{$story->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('stories', ['id' => $story->id]);
});

test('l execution repetee de la commande stories:expire est idempotente', function () {
    $expiredStory = Story::factory()->create([
        'user_id' => $this->user->id,
        'status' => StoryStatus::Active,
        'allow_archive' => true,
        'expires_at' => now()->subHours(2),
    ]);

    // Première exécution
    Artisan::call('stories:expire');
    expect($expiredStory->fresh()->status)->toBe(StoryStatus::Archived);

    // Seconde exécution : reste archivé sans erreur
    Artisan::call('stories:expire');
    expect($expiredStory->fresh()->status)->toBe(StoryStatus::Archived);
});

test('un utilisateur ne peut pas supprimer la Story d un autre utilisateur', function () {
    $otherUser = User::factory()->create();
    $story = Story::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson("/api/stories/{$story->id}");
    $response->assertStatus(403);

    $this->assertDatabaseHas('stories', [
        'id' => $story->id,
        'deleted_at' => null,
    ]);
});
