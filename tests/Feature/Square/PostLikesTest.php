<?php

use App\Models\Square\Post;
use App\Models\Square\PostLike;
use App\Models\User;
use App\Services\Square\PostLikeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('permet a un utilisateur d aimer une publication accessible et incremente likes_count', function () {
    $author = User::factory()->create();
    $fan = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Belle journée à Bafoussam',
        'audience' => 'public',
        'likes_count' => 0,
    ]);

    Passport::actingAs($fan);

    $response = postJson("/api/square-cr1/posts/{$post->id}/like");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'liked' => true,
            'likes_count' => 1,
            'post_id' => $post->id,
        ]);

    $this->assertDatabaseHas('post_likes', [
        'post_id' => $post->id,
        'user_id' => $fan->id,
    ]);

    $post->refresh();
    expect($post->likes_count)->toBe(1);
});

it('est idempotent lors d appels repetes pour aimer une publication sans doubler likes_count', function () {
    $author = User::factory()->create();
    $fan = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post Square',
        'audience' => 'public',
        'likes_count' => 0,
    ]);

    Passport::actingAs($fan);

    // 1er appel
    postJson("/api/square-cr1/posts/{$post->id}/like")
        ->assertStatus(200)
        ->assertJson(['liked' => true, 'likes_count' => 1]);

    // 2ème appel identique (retry)
    postJson("/api/square-cr1/posts/{$post->id}/like")
        ->assertStatus(200)
        ->assertJson(['liked' => true, 'likes_count' => 1]);

    expect(PostLike::where('post_id', $post->id)->where('user_id', $fan->id)->count())->toBe(1);
    $post->refresh();
    expect($post->likes_count)->toBe(1);
});

it('permet a un utilisateur de retirer son like et decremente likes_count sans devenir negatif', function () {
    $author = User::factory()->create();
    $fan = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post à déliker',
        'audience' => 'public',
        'likes_count' => 1,
    ]);

    PostLike::create(['post_id' => $post->id, 'user_id' => $fan->id]);

    Passport::actingAs($fan);

    $response = deleteJson("/api/square-cr1/posts/{$post->id}/like");

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'liked' => false,
            'likes_count' => 0,
            'post_id' => $post->id,
        ]);

    $this->assertDatabaseMissing('post_likes', [
        'post_id' => $post->id,
        'user_id' => $fan->id,
    ]);

    $post->refresh();
    expect($post->likes_count)->toBe(0);

    // Un deuxième retrait ne rend pas le compteur négatif
    deleteJson("/api/square-cr1/posts/{$post->id}/like")
        ->assertStatus(200)
        ->assertJson(['liked' => false, 'likes_count' => 0]);

    $post->refresh();
    expect($post->likes_count)->toBe(0);
});

it('interdit d aimer une publication inaccessible pour cause de blocage', function () {
    $author = User::factory()->create();
    $blockedUser = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post privé pour bloqué',
        'audience' => 'public',
    ]);

    $author->blockedUsers()->attach($blockedUser->id);

    Passport::actingAs($blockedUser);

    postJson("/api/square-cr1/posts/{$post->id}/like")
        ->assertStatus(403);

    expect(PostLike::count())->toBe(0);
});

it('affiche letat liked et likes_count dans la ressource du post et dans le feed', function () {
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post avec like actif',
        'audience' => 'public',
        'likes_count' => 1,
    ]);

    PostLike::create(['post_id' => $post->id, 'user_id' => $viewer->id]);

    Passport::actingAs($viewer);

    // Détail du post
    getJson("/api/square-cr1/posts/{$post->id}")
        ->assertStatus(200)
        ->assertJsonPath('data.liked', true)
        ->assertJsonPath('data.likes_count', 1);

    // Fil découverte
    $feedRes = getJson('/api/square-cr1/square/feed?tab=discover');
    $feedRes->assertStatus(200);

    $item = collect($feedRes->json('data'))->firstWhere('id', (string) $post->id);
    expect($item)->not->toBeNull()
        ->and($item['liked'])->toBeTrue()
        ->and($item['likes_count'])->toBe(1);
});

it('retourne la liste paginee de mes publications aimees les plus recentes d abord', function () {
    $author = User::factory()->create();
    $me = User::factory()->create();

    $post1 = Post::create([
        'user_id' => $author->id,
        'body' => 'Premier post aimé',
        'audience' => 'public',
        'likes_count' => 1,
    ]);

    $post2 = Post::create([
        'user_id' => $author->id,
        'body' => 'Deuxième post aimé',
        'audience' => 'public',
        'likes_count' => 1,
    ]);

    $postNonAime = Post::create([
        'user_id' => $author->id,
        'body' => 'Post non aimé',
        'audience' => 'public',
        'likes_count' => 0,
    ]);

    // Aimer post1 puis post2
    app(PostLikeService::class)->like($post1, $me);
    // Avancer le temps pour que post2 soit plus récent
    $this->travel(5)->minutes();
    app(PostLikeService::class)->like($post2, $me);

    Passport::actingAs($me);

    $response = getJson('/api/square-cr1/posts/liked');

    $response->assertStatus(200);
    $data = $response->json('data');

    expect(count($data))->toBe(2);
    // Plus récent d'abord (post2 puis post1)
    expect((int) $data[0]['id'])->toBe($post2->id);
    expect($data[0]['liked'])->toBeTrue();
    expect((int) $data[1]['id'])->toBe($post1->id);
    expect($data[1]['liked'])->toBeTrue();
});
