<?php

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Reel\ReelCommentReaction;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostCommentReaction;
use App\Models\User;
use App\Services\Reel\ReelCommentService;
use App\Services\Square\PostCommentReactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

test('aimer un commentaire de reel ou square deux fois simultanement ne renvoie jamais une erreur 500 en cas de collision unique', function () {
    $comment = ReelComment::create([
        'reel_id' => $this->reel->id,
        'user_id' => $this->user->id,
        'body' => 'Commentaire concurrent',
        'likes_count' => 0,
        'dislikes_count' => 0,
    ]);

    // Simuler une insertion concurrente juste au moment où ReelCommentReaction::create() est appelé
    $simulatedRace = false;
    ReelCommentReaction::creating(function (ReelCommentReaction $reaction) use (&$simulatedRace) {
        if (! $simulatedRace) {
            $simulatedRace = true;
            DB::table('reel_comment_reactions')->insert([
                'comment_id' => $reaction->comment_id,
                'user_id' => $reaction->user_id,
                'type' => 'like',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('reel_comments')->where('id', $reaction->comment_id)->increment('likes_count');
        }
    });

    $res = $this->putJson("/api/reels/comments/{$comment->id}/reaction", [
        'type' => 'like',
    ]);

    $res->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_reaction', 'like')
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.dislikes_count', 0);

    ReelCommentReaction::flushEventListeners();

    // Même vérification sur un commentaire Square (PostCommentReactionService)
    $post = Post::factory()->create(['user_id' => $this->user->id]);
    $postComment = PostComment::create([
        'post_id' => $post->id,
        'user_id' => $this->user->id,
        'body' => 'Commentaire Square concurrent',
        'likes_count' => 0,
        'dislikes_count' => 0,
    ]);

    $simulatedSquareRace = false;
    PostCommentReaction::creating(function (PostCommentReaction $reaction) use (&$simulatedSquareRace) {
        if (! $simulatedSquareRace) {
            $simulatedSquareRace = true;
            DB::table('post_comment_reactions')->insert([
                'comment_id' => $reaction->comment_id,
                'user_id' => $reaction->user_id,
                'type' => 'like',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('post_comments')->where('id', $reaction->comment_id)->increment('likes_count');
        }
    });

    $squareResult = app(PostCommentReactionService::class)->react($postComment, $this->user, 'like');
    expect($squareResult['my_reaction'])->toBe('like')
        ->and($squareResult['user_reaction'])->toBe('like')
        ->and($squareResult['likes_count'])->toBe(1)
        ->and($squareResult['dislikes_count'])->toBe(0);

    PostCommentReaction::flushEventListeners();
});
