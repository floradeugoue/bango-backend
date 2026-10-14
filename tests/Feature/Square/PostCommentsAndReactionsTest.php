<?php

use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('permet a un utilisateur de publier un commentaire valide sur un post et incremente comments_count', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post de test',
        'audience' => 'public',
        'comments_count' => 0,
    ]);

    Passport::actingAs($commenter);

    $response = postJson("/api/square-cr1/posts/{$post->id}/comments", [
        'body' => 'Super publication, félicitations !',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.body', 'Super publication, félicitations !')
        ->assertJsonPath('data.postId', $post->id)
        ->assertJsonPath('data.mine', true)
        ->assertJsonPath('data.user.id', $commenter->id);

    $this->assertDatabaseHas('post_comments', [
        'post_id' => $post->id,
        'user_id' => $commenter->id,
        'body' => 'Super publication, félicitations !',
        'parent_id' => null,
    ]);

    $post->refresh();
    expect($post->comments_count)->toBe(1);
});

it('refuse un commentaire vide ou constitue uniquement despaces', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);

    Passport::actingAs($commenter);

    postJson("/api/square-cr1/posts/{$post->id}/comments", ['body' => '   '])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);

    postJson("/api/square-cr1/posts/{$post->id}/comments", ['body' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

it('refuse un commentaire depassant la limite de caracteres', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);

    Passport::actingAs($commenter);

    $longText = str_repeat('A', 2201);

    postJson("/api/square-cr1/posts/{$post->id}/comments", ['body' => $longText])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

it('liste les commentaires de premier niveau de maniere paginee', function () {
    $author = User::factory()->create();
    $post = Post::create(['user_id' => $author->id, 'body' => 'Post avec commentaires']);

    $c1 = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'Commentaire 1']);
    $c2 = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'Commentaire 2']);

    Passport::actingAs($author);

    $response = getJson("/api/square-cr1/posts/{$post->id}/comments");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('permet a lauteur de modifier son propre commentaire', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);
    $comment = PostComment::create([
        'post_id' => $post->id,
        'user_id' => $commenter->id,
        'body' => 'Texte original',
    ]);

    Passport::actingAs($commenter);

    $response = patchJson("/api/square-cr1/comments/{$comment->id}", [
        'body' => 'Texte corrigé',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.body', 'Texte corrigé')
        ->assertJsonPath('data.isEdited', true);

    $comment->refresh();
    expect($comment->body)->toBe('Texte corrigé')
        ->and($comment->is_edited)->toBeTrue();
});

it('refuse la modification dun commentaire par un autre utilisateur', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();
    $stranger = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);
    $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $commenter->id, 'body' => 'Texte']);

    Passport::actingAs($stranger);

    patchJson("/api/square-cr1/comments/{$comment->id}", ['body' => 'Piratage'])
        ->assertStatus(403);
});

it('permet a lauteur du commentaire de le supprimer et decremente comments_count', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post', 'comments_count' => 1]);
    $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $commenter->id, 'body' => 'Texte']);

    Passport::actingAs($commenter);

    deleteJson("/api/square-cr1/comments/{$comment->id}")
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(PostComment::find($comment->id))->toBeNull();
    $post->refresh();
    expect($post->comments_count)->toBe(0);
});

it('permet a lauteur du post de supprimer nimporte quel commentaire sur son post', function () {
    $author = User::factory()->create();
    $troll = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post', 'comments_count' => 1]);
    $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $troll->id, 'body' => 'Spam']);

    Passport::actingAs($author);

    deleteJson("/api/square-cr1/comments/{$comment->id}")
        ->assertStatus(200);

    expect(PostComment::find($comment->id))->toBeNull();
});

it('permet de repondre a un commentaire et incremente replies_count et comments_count', function () {
    $author = User::factory()->create();
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post', 'comments_count' => 1]);
    $parent = PostComment::create(['post_id' => $post->id, 'user_id' => $user1->id, 'body' => 'Question']);

    Passport::actingAs($user2);

    $response = postJson("/api/square-cr1/posts/{$post->id}/comments", [
        'body' => 'Réponse explicative',
        'parent_id' => $parent->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.parentId', $parent->id)
        ->assertJsonPath('data.body', 'Réponse explicative');

    $parent->refresh();
    $post->refresh();
    expect($parent->replies_count)->toBe(1)
        ->and($post->comments_count)->toBe(2);

    // Consultation des réponses
    $repliesRes = getJson("/api/square-cr1/comments/{$parent->id}/replies");
    $repliesRes->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

it('refuse une reponse avec un parent_id d un autre post', function () {
    $author = User::factory()->create();
    $user = User::factory()->create();

    $post1 = Post::create(['user_id' => $author->id, 'body' => 'Post 1']);
    $post2 = Post::create(['user_id' => $author->id, 'body' => 'Post 2']);

    $parentOtherPost = PostComment::create(['post_id' => $post2->id, 'user_id' => $user->id, 'body' => 'Com sur post 2']);

    Passport::actingAs($user);

    postJson("/api/square-cr1/posts/{$post1->id}/comments", [
        'body' => 'Réponse pirate',
        'parent_id' => $parentOtherPost->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

it('refuse une reponse a une reponse (limite de profondeur 1 niveau)', function () {
    $author = User::factory()->create();
    $user = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);
    $parent = PostComment::create(['post_id' => $post->id, 'user_id' => $user->id, 'body' => 'Niveau 0']);
    $reply = PostComment::create(['post_id' => $post->id, 'user_id' => $user->id, 'parent_id' => $parent->id, 'body' => 'Niveau 1']);

    Passport::actingAs($user);

    postJson("/api/square-cr1/posts/{$post->id}/comments", [
        'body' => 'Tentative niveau 2',
        'parent_id' => $reply->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['parent_id']);
});

it('supprime en cascade les reponses lors de la suppression du commentaire parent', function () {
    $author = User::factory()->create();
    $post = Post::create(['user_id' => $author->id, 'body' => 'Post', 'comments_count' => 3]);

    $parent = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'Parent']);
    $reply1 = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'parent_id' => $parent->id, 'body' => 'R1']);
    $reply2 = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'parent_id' => $parent->id, 'body' => 'R2']);

    Passport::actingAs($author);

    deleteJson("/api/square-cr1/comments/{$parent->id}")
        ->assertStatus(200);

    $post->refresh();
    expect($post->comments_count)->toBe(0)
        ->and(PostComment::find($parent->id))->toBeNull()
        ->and(PostComment::find($reply1->id))->toBeNull()
        ->and(PostComment::find($reply2->id))->toBeNull();
});

it('respecte comments_closed et refuse tout nouveau commentaire', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post fermé aux commentaires',
        'comments_closed' => true,
    ]);

    Passport::actingAs($stranger);

    postJson("/api/square-cr1/posts/{$post->id}/comments", ['body' => 'Commentaire refusé'])
        ->assertStatus(403);
});

it('respecte comments_by followers et refuse les commentaires de non-abonnes', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post réservé aux followers',
        'comments_by' => 'followers',
    ]);

    Passport::actingAs($stranger);

    // Non abonné -> 403
    postJson("/api/square-cr1/posts/{$post->id}/comments", ['body' => 'Refusé'])
        ->assertStatus(403);

    // L'abonné -> 201
    $stranger->following()->attach($author->id);

    postJson("/api/square-cr1/posts/{$post->id}/comments", ['body' => 'Accepté car abonné'])
        ->assertStatus(201);
});

it('permet d ajouter, modifier et retirer des reactions like et dislike sur un commentaire', function () {
    $author = User::factory()->create();
    $reactor = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);
    $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'Commentaire cool']);

    Passport::actingAs($reactor);

    // 1. Ajouter un like
    $res1 = putJson("/api/square-cr1/comments/{$comment->id}/reaction", ['type' => 'like']);
    $res1->assertStatus(200)
        ->assertJson([
            'success' => true,
            'my_reaction' => 'like',
            'likes_count' => 1,
            'dislikes_count' => 0,
        ]);

    // 2. Basculer vers dislike
    $res2 = putJson("/api/square-cr1/comments/{$comment->id}/reaction", ['type' => 'dislike']);
    $res2->assertStatus(200)
        ->assertJson([
            'success' => true,
            'my_reaction' => 'dislike',
            'likes_count' => 0,
            'dislikes_count' => 1,
        ]);

    // 3. Basculer (toggle) off en réappliquant dislike
    $res3 = putJson("/api/square-cr1/comments/{$comment->id}/reaction", ['type' => 'dislike']);
    $res3->assertStatus(200)
        ->assertJson([
            'success' => true,
            'my_reaction' => null,
            'likes_count' => 0,
            'dislikes_count' => 0,
        ]);

    // 4. Ré-ajouter like puis suppression explicite via DELETE
    putJson("/api/square-cr1/comments/{$comment->id}/reaction", ['type' => 'like']);

    $resDelete = deleteJson("/api/square-cr1/comments/{$comment->id}/reaction");
    $resDelete->assertStatus(200)
        ->assertJson([
            'success' => true,
            'my_reaction' => null,
            'likes_count' => 0,
            'dislikes_count' => 0,
        ]);
});

it('refuse une reaction avec un type invalide', function () {
    $author = User::factory()->create();
    $user = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post']);
    $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'Commentaire']);

    Passport::actingAs($user);

    putJson("/api/square-cr1/comments/{$comment->id}/reaction", ['type' => 'love'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type']);
});

it('refuse de reagir a un commentaire d une publication bloquee', function () {
    $author = User::factory()->create();
    $blockedUser = User::factory()->create();

    $post = Post::create(['user_id' => $author->id, 'body' => 'Post bloqué']);
    $comment = PostComment::create(['post_id' => $post->id, 'user_id' => $author->id, 'body' => 'Commentaire secret']);

    $author->blockedUsers()->attach($blockedUser->id);

    Passport::actingAs($blockedUser);

    putJson("/api/square-cr1/comments/{$comment->id}/reaction", ['type' => 'like'])
        ->assertStatus(403);
});
