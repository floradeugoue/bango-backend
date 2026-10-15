<?php

use App\Enums\Story\StoryAudience;
use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = User::factory()->create(['display_name' => 'Auteur Story']);
    $this->viewer = User::factory()->create(['display_name' => 'Spectateur Story']);
    Passport::actingAs($this->viewer);
});

test('un utilisateur non authentifié ne peut pas accéder aux Stories', function () {
    // Resetting authentication
    $story = Story::factory()->create();
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/stories/rail')->assertStatus(401);
    $this->getJson("/api/stories/{$story->id}")->assertStatus(401);
});

test('une Story avec audience everyone est accessible à tout utilisateur', function () {
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'audience' => StoryAudience::Everyone,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id, 'caption' => 'Story publique']);

    $response = $this->getJson("/api/stories/{$story->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', (string) $story->id)
        ->assertJsonPath('data.slides.0.caption', 'Story publique');
});

test('une Story followers est réservée aux abonnés autorisés', function () {
    $story = Story::factory()->followers()->create([
        'user_id' => $this->author->id,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    // Spectateur ne suit pas l'auteur : accès refusé
    $resBefore = $this->getJson("/api/stories/{$story->id}");
    $resBefore->assertStatus(404);

    // Le spectateur s'abonne à l'auteur
    $this->viewer->following()->attach($this->author->id);

    $resAfter = $this->getJson("/api/stories/{$story->id}");
    $resAfter->assertStatus(200)
        ->assertJsonPath('data.id', (string) $story->id);
});

test('une Story chosen est accessible uniquement si le spectateur figure dans la liste choisie', function () {
    $story = Story::factory()->chosen()->create([
        'user_id' => $this->author->id,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    // Non présent dans close friends
    $this->getJson("/api/stories/{$story->id}")->assertStatus(404);

    // L'auteur ajoute le spectateur dans sa liste choisie
    $this->author->closeFriends()->attach($this->viewer->id);

    $this->getJson("/api/stories/{$story->id}")->assertStatus(200);
});

test('un compte bloqué ne peut pas accéder à la Story', function () {
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'audience' => StoryAudience::Everyone,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    // Blocage par l'auteur
    $this->author->blockedUsers()->attach($this->viewer->id);

    $this->getJson("/api/stories/{$story->id}")->assertStatus(404);
});

test('une Story expirée retourne 410 Gone pour les tiers mais reste consultable par son auteur', function () {
    $story = Story::factory()->expired()->create([
        'user_id' => $this->author->id,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    // Tiers : 410 Gone
    $this->getJson("/api/stories/{$story->id}")->assertStatus(410);

    // Auteur connecté : accès permis pour historique
    Passport::actingAs($this->author);
    $this->getJson("/api/stories/{$story->id}")->assertStatus(200);
});

test('le rail retourne les Stories actives triées avec les non vues en priorité', function () {
    // 1. Story de l'auteur suivie par le spectateur
    $this->viewer->following()->attach($this->author->id);

    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'audience' => StoryAudience::Everyone,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $response = $this->getJson('/api/stories/rail');

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.user.id', $this->author->id)
        ->assertJsonPath('data.0.has_unviewed', true)
        ->assertJsonPath('data.0.stories_count', 1);
});
