<?php

use App\Enums\Story\StoryReplyType;
use App\Events\Story\StoryReplyCreated;
use App\Models\Story\Story;
use App\Models\Story\StoryReply;
use App\Models\Story\StorySlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = User::factory()->create(['display_name' => 'Auteur']);
    $this->respondent = User::factory()->create(['display_name' => 'Répondant']);
    Passport::actingAs($this->respondent);
});

test('un utilisateur peut envoyer une réponse textuelle privée sur une Story active', function () {
    Event::fake([StoryReplyCreated::class]);

    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'allow_replies' => true,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id, 'caption' => 'Aperçu superbe']);

    $response = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Magnifique photo ! C\'était où ?',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.story_id', (string) $story->id)
        ->assertJsonPath('data.type', StoryReplyType::Text->value)
        ->assertJsonPath('data.text', 'Magnifique photo ! C\'était où ?')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('story_replies', [
        'story_id' => $story->id,
        'story_author_id' => $this->author->id,
        'user_id' => $this->respondent->id,
        'type' => StoryReplyType::Text->value,
        'text' => 'Magnifique photo ! C\'était où ?',
        'status' => 'pending',
    ]);

    Event::assertDispatched(StoryReplyCreated::class, function ($event) use ($story) {
        return $event->reply->story_id === $story->id
            && $event->integrationPayload['event'] === 'story.reply.created'
            && $event->integrationPayload['author_id'] === $this->author->id
            && $event->integrationPayload['respondent_id'] === $this->respondent->id;
    });
});

test('un utilisateur peut envoyer une réaction rapide emoji sur une Story', function () {
    Event::fake([StoryReplyCreated::class]);

    $story = Story::factory()->create(['user_id' => $this->author->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $response = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'reaction',
        'reaction' => '🔥',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', StoryReplyType::Reaction->value)
        ->assertJsonPath('data.reaction', '🔥');

    $this->assertDatabaseHas('story_replies', [
        'story_id' => $story->id,
        'reaction' => '🔥',
        'type' => StoryReplyType::Reaction->value,
    ]);
});

test('la réponse privée est refusée si l auteur a désactivé les réponses', function () {
    $story = Story::factory()->create([
        'user_id' => $this->author->id,
        'allow_replies' => false,
    ]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $response = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Bonjour',
    ]);

    $response->assertStatus(403);
});

test('l auteur ne peut pas s auto répondre en privé', function () {
    Passport::actingAs($this->author);

    $story = Story::factory()->create(['user_id' => $this->author->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $response = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Auto-réponse',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['reply']);
});

test('la réponse est refusée sur une Story expirée', function () {
    $story = Story::factory()->expired()->create(['user_id' => $this->author->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    $response = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Trop tard',
    ]);

    $response->assertStatus(410);
});

test('la répétition de la même requête avec clé d idempotence renvoie la réponse existante', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);
    $idempotencyKey = (string) Str::uuid();

    // Premier envoi
    $res1 = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Premier essai',
        'idempotency_key' => $idempotencyKey,
    ]);
    $res1->assertStatus(201);
    $replyId = $res1->json('data.id');

    // Deuxième envoi répété avec la même clé
    $res2 = $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => 'Deuxième essai avec même clé',
        'idempotency_key' => $idempotencyKey,
    ]);

    $res2->assertStatus(201)
        ->assertJsonPath('data.id', $replyId);

    // Vérification qu'un seul enregistrement existe en base
    expect(StoryReply::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

test('la réponse ciblant une diapositive d une autre Story est rejetée avec 422', function () {
    $story1 = Story::factory()->create(['user_id' => $this->author->id]);
    $story2 = Story::factory()->create(['user_id' => $this->author->id]);
    $slide2 = StorySlide::factory()->create(['story_id' => $story2->id]);

    $response = $this->postJson("/api/stories/{$story1->id}/reply", [
        'type' => 'text',
        'text' => 'Ciblage slide invalide',
        'slide_id' => $slide2->id,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['slide_id']);
});

test('la réponse textuelle vide ou la réaction sans emoji sont rejetées avec 422', function () {
    $story = Story::factory()->create(['user_id' => $this->author->id]);
    StorySlide::factory()->create(['story_id' => $story->id]);

    // Texte vide
    $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'text',
        'text' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['text']);

    // Réaction sans émoji
    $this->postJson("/api/stories/{$story->id}/reply", [
        'type' => 'reaction',
        'reaction' => '',
    ])->assertStatus(422)->assertJsonValidationErrors(['reaction']);
});
