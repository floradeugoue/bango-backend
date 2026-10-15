<?php

use App\Enums\Story\StoryAudience;
use App\Models\Story\Story;
use App\Models\Story\StoryHighlight;
use App\Models\Story\StorySlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->friend1 = User::factory()->create();
    $this->friend2 = User::factory()->create();
    Passport::actingAs($this->user);
});

test('un utilisateur peut consulter et mettre à jour sa liste choisie atomiquement', function () {
    // 1. Liste initiale vide
    $resInit = $this->getJson('/api/stories/close-list');
    $resInit->assertStatus(200)
        ->assertJsonPath('data', []);

    // 2. Remplacement atomique avec deux amis
    $resUpdate = $this->putJson('/api/stories/close-list', [
        'user_ids' => [$this->friend1->id, $this->friend2->id],
    ]);

    $resUpdate->assertStatus(200)
        ->assertJsonCount(2, 'data');

    $this->assertDatabaseHas('user_close_friends', [
        'user_id' => $this->user->id,
        'friend_id' => $this->friend1->id,
    ]);
    $this->assertDatabaseHas('user_close_friends', [
        'user_id' => $this->user->id,
        'friend_id' => $this->friend2->id,
    ]);

    // 3. Remplacement avec un seul ami
    $resUpdate2 = $this->putJson('/api/stories/close-list', [
        'user_ids' => [$this->friend1->id],
    ]);
    $resUpdate2->assertStatus(200)
        ->assertJsonCount(1, 'data');

    $this->assertDatabaseMissing('user_close_friends', [
        'user_id' => $this->user->id,
        'friend_id' => $this->friend2->id,
    ]);
});

test('la mise a jour de la liste choisie exclut soi-même et refuse les utilisateurs inexistants', function () {
    // Utilisateur inexistant
    $resInvalid = $this->putJson('/api/stories/close-list', [
        'user_ids' => [999999],
    ]);
    $resInvalid->assertStatus(422);

    // Tentative de s'ajouter soi-même : automatiquement exclu de la relation
    $resSelf = $this->putJson('/api/stories/close-list', [
        'user_ids' => [$this->user->id, $this->friend1->id],
    ]);
    $resSelf->assertStatus(200)
        ->assertJsonCount(1, 'data');

    $this->assertDatabaseMissing('user_close_friends', [
        'user_id' => $this->user->id,
        'friend_id' => $this->user->id,
    ]);
});

test('un utilisateur peut créer une collection À la une avec titre limité à 20 caractères', function () {
    $story1 = Story::factory()->create(['user_id' => $this->user->id]);
    $story2 = Story::factory()->create(['user_id' => $this->user->id]);
    StorySlide::factory()->create(['story_id' => $story1->id]);
    StorySlide::factory()->create(['story_id' => $story2->id]);

    $response = $this->postJson('/api/highlights', [
        'title' => 'Vacances Kribi', // 14 caractères (<= 20)
        'story_ids' => [$story1->id, $story2->id],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.title', 'Vacances Kribi')
        ->assertJsonPath('data.stories_count', 2);

    $this->assertDatabaseHas('story_highlights', [
        'user_id' => $this->user->id,
        'title' => 'Vacances Kribi',
    ]);
});

test('la création d une collection échoue si le titre dépasse 20 caractères', function () {
    $story = Story::factory()->create(['user_id' => $this->user->id]);

    $response = $this->postJson('/api/highlights', [
        'title' => 'Mon Voyage Exceptionnel 2026 Au Cameroun', // > 20 caractères
        'story_ids' => [$story->id],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['title']);
});

test('la suppression d une collection ne supprime pas les Stories associées', function () {
    $story = Story::factory()->create(['user_id' => $this->user->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $highlight = StoryHighlight::factory()->create(['user_id' => $this->user->id, 'title' => 'Mes Voyages']);
    $highlight->stories()->attach($story->id);

    $response = $this->deleteJson("/api/highlights/{$highlight->id}");
    $response->assertStatus(200);

    // La collection est supprimée
    $this->assertDatabaseMissing('story_highlights', ['id' => $highlight->id]);
    // Mais la Story existe toujours intacte !
    $this->assertDatabaseHas('stories', ['id' => $story->id]);
});

test('les Stories dans une collection conservent leurs règles d audience et ne deviennent pas publiques', function () {
    $author = User::factory()->create();
    $chosenStory = Story::factory()->chosen()->create(['user_id' => $author->id]);
    $publicStory = Story::factory()->create(['user_id' => $author->id, 'audience' => StoryAudience::Everyone]);

    StorySlide::factory()->create(['story_id' => $chosenStory->id]);
    StorySlide::factory()->create(['story_id' => $publicStory->id]);

    $highlight = StoryHighlight::factory()->create(['user_id' => $author->id, 'title' => 'Mes Souvenirs']);
    $highlight->stories()->attach([$chosenStory->id => ['order' => 0], $publicStory->id => ['order' => 1]]);

    // Le spectateur n'est pas dans la liste choisie de l'auteur : il ne doit voir QUE la story publique
    $response = $this->getJson("/api/highlights/{$highlight->id}");

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.stories')
        ->assertJsonPath('data.stories.0.id', (string) $publicStory->id);
});
