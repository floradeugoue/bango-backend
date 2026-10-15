<?php

use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->reel = Reel::factory()->create(['comments_count' => 0, 'comments_closed' => false]);
    Passport::actingAs($this->user);
});

test('un utilisateur peut commenter un reel et le compteur est incremente', function () {
    $response = $this->postJson("/api/reels/{$this->reel->id}/comments", [
        'body' => 'Superbe vidéo, j\'adore la musique !',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.body', 'Superbe vidéo, j\'adore la musique !')
        ->assertJsonPath('data.author.id', $this->user->id);

    expect($this->reel->fresh()->comments_count)->toBe(1);
});

test('un utilisateur ne peut pas commenter un reel si les commentaires sont fermes', function () {
    $this->reel->update(['comments_closed' => true]);

    $response = $this->postJson("/api/reels/{$this->reel->id}/comments", [
        'body' => 'Commentaire qui doit échouer',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['comment']);

    expect($this->reel->fresh()->comments_count)->toBe(0);
});

test('un utilisateur peut repondre a un commentaire existant', function () {
    $parent = ReelComment::create([
        'reel_id' => $this->reel->id,
        'user_id' => $this->user->id,
        'body' => 'Premier commentaire',
    ]);
    $this->reel->increment('comments_count');

    $response = $this->postJson("/api/reels/{$this->reel->id}/comments", [
        'parent_id' => $parent->id,
        'body' => 'Je suis totalement d\'accord avec toi !',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.parent_id', (string) $parent->id);

    expect($parent->fresh()->replies_count)->toBe(1)
        ->and($this->reel->fresh()->comments_count)->toBe(2);

    $repliesResponse = $this->getJson("/api/reels/comments/{$parent->id}/replies");
    $repliesResponse->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

test('un utilisateur peut modifier son propre commentaire', function () {
    $comment = ReelComment::create([
        'reel_id' => $this->reel->id,
        'user_id' => $this->user->id,
        'body' => 'Texte original',
    ]);

    $response = $this->patchJson("/api/reels/comments/{$comment->id}", [
        'body' => 'Texte corrigé',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.body', 'Texte corrigé')
        ->assertJsonPath('data.is_edited', true);
});

test('un utilisateur peut supprimer son commentaire et le compteur se decremente', function () {
    $comment = ReelComment::create([
        'reel_id' => $this->reel->id,
        'user_id' => $this->user->id,
        'body' => 'Commentaire à supprimer',
    ]);
    $this->reel->increment('comments_count');

    $response = $this->deleteJson("/api/reels/comments/{$comment->id}");

    $response->assertStatus(200);
    $this->assertSoftDeleted('reel_comments', ['id' => $comment->id]);
    expect($this->reel->fresh()->comments_count)->toBe(0);
});

test('reactions sur les commentaires avec exclusivite like et dislike', function () {
    $comment = ReelComment::create([
        'reel_id' => $this->reel->id,
        'user_id' => $this->user->id,
        'body' => 'Commentaire test',
        'likes_count' => 0,
        'dislikes_count' => 0,
    ]);

    // Like commentaire
    $likeRes = $this->putJson("/api/reels/comments/{$comment->id}/reaction", [
        'type' => 'like',
    ]);
    $likeRes->assertStatus(200)
        ->assertJsonPath('data.user_reaction', 'like')
        ->assertJsonPath('data.likes_count', 1);

    // Dislike commentaire (remplace le like)
    $dislikeRes = $this->putJson("/api/reels/comments/{$comment->id}/reaction", [
        'type' => 'dislike',
    ]);
    $dislikeRes->assertStatus(200)
        ->assertJsonPath('data.user_reaction', 'dislike')
        ->assertJsonPath('data.likes_count', 0)
        ->assertJsonPath('data.dislikes_count', 1);
});
