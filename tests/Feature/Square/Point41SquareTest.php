<?php

use App\Enums\Square\PostAudience;
use App\Models\Profile\Follow;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostLike;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('square feed with author_id does not leak followers-only posts to non-followers', function () {
    $author = User::factory()->create(['name' => 'Auteur']);
    $stranger = User::factory()->create(['name' => 'Inconnu']);

    $publicPost = Post::create([
        'user_id' => $author->id,
        'body' => 'Post public',
        'audience' => PostAudience::Public->value,
    ]);

    $followersPost = Post::create([
        'user_id' => $author->id,
        'body' => 'Post abonnés uniquement',
        'audience' => PostAudience::Followers->value,
    ]);

    Passport::actingAs($stranger);

    // 1. In feed: only public post is returned
    $response = getJson("/api/square/feed?author_id={$author->id}");
    $response->assertOk();
    $postIds = collect($response->json('data'))->pluck('id')->map(fn ($id) => (string) $id)->all();

    expect($postIds)->toContain((string) $publicPost->id)
        ->and($postIds)->not->toContain((string) $followersPost->id);

    // 2. Direct show returns 403 Forbidden
    getJson("/api/posts/{$followersPost->id}")->assertStatus(403);

    // 3. When following the author, the followers post is visible in feed and show
    Follow::create([
        'follower_id' => $stranger->id,
        'following_id' => $author->id,
    ]);

    $responseFollower = getJson("/api/square/feed?author_id={$author->id}");
    $followerPostIds = collect($responseFollower->json('data'))->pluck('id')->map(fn ($id) => (string) $id)->all();
    expect($followerPostIds)->toContain((string) $followersPost->id);

    getJson("/api/posts/{$followersPost->id}")->assertOk();
});

test('details of a post by a suspended author is not accessible', function () {
    $suspendedAuthor = User::factory()->create([
        'name' => 'Auteur Suspendu',
        'status' => 'suspended',
    ]);
    $viewer = User::factory()->create(['name' => 'Lecteur']);

    $post = Post::create([
        'user_id' => $suspendedAuthor->id,
        'body' => 'Post suspendu',
        'audience' => PostAudience::Public->value,
    ]);

    Passport::actingAs($viewer);

    getJson("/api/posts/{$post->id}")->assertStatus(403);
});

test('create post rejects external unmoderated media URLs and accepts storage URLs', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // External suspicious URL
    $resReject = postJson('/api/square-cr1/posts', [
        'caption' => 'Test tracking pixel',
        'media' => [
            ['url' => 'https://malicious-tracking-pixel.com/track.gif'],
        ],
    ]);

    $resReject->assertStatus(422)
        ->assertJsonValidationErrors(['media.0.url']);

    // Valid BANGO storage URL
    $resAccept = postJson('/api/square-cr1/posts', [
        'caption' => 'Test valid upload',
        'media' => [
            ['url' => 'https://storage.bango.cm/square/photo.jpg'],
        ],
    ]);

    $resAccept->assertCreated();
});

test('likes endpoint and pin comment endpoint work correctly', function () {
    $author = User::factory()->create(['name' => 'Auteur Post']);
    $fan = User::factory()->create(['name' => 'Fan']);
    Passport::actingAs($author);

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Publication pour likes et pin',
        'audience' => PostAudience::Public->value,
    ]);

    $comment = PostComment::create([
        'post_id' => $post->id,
        'user_id' => $fan->id,
        'body' => 'Super publication !',
    ]);

    PostLike::create([
        'post_id' => $post->id,
        'user_id' => $fan->id,
    ]);
    $post->increment('likes_count');

    // 1. GET /posts/{id}/likes (not 404)
    $resLikes = getJson("/api/posts/{$post->id}/likes");
    $resLikes->assertOk();
    expect($resLikes->json('data'))->toHaveCount(1);
    expect($resLikes->json('data.0.id'))->toBe($fan->id);

    // 2. POST /posts/{id}/pin-comment (not 404)
    $resPin = postJson("/api/posts/{$post->id}/pin-comment", [
        'comment_id' => $comment->id,
    ]);
    $resPin->assertOk()
        ->assertJsonPath('is_pinned', true)
        ->assertJsonPath('pinned_comment_id', $comment->id);

    // Verify in post show
    $resShow = getJson("/api/posts/{$post->id}");
    $resShow->assertOk()
        ->assertJsonPath('data.pinned_comment_id', $comment->id);

    // Unpin toggle
    $resUnpin = postJson("/api/posts/{$post->id}/pin-comment", [
        'comment_id' => $comment->id,
    ]);
    $resUnpin->assertOk()
        ->assertJsonPath('is_pinned', false);
});
