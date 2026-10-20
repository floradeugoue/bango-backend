<?php

use App\Models\Square\Hashtag;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('point 48: comments accept and expose answer_to_id', function () {
    $author = User::factory()->create();
    $user = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post de test pour commentaires',
        'visibility' => 'public',
        'status' => 'published',
    ]);

    // Parent comment
    $parentRes = $this->actingAs($user, 'api')->postJson("/api/posts/{$post->id}/comments", [
        'body' => 'Premier commentaire',
    ]);
    $parentRes->assertCreated();
    $parentId = $parentRes->json('data.id');

    // Reply using answer_to_id
    $replyRes = $this->actingAs($user, 'api')->postJson("/api/posts/{$post->id}/comments", [
        'body' => 'Réponse au premier commentaire',
        'answer_to_id' => $parentId,
    ]);

    $replyRes->assertCreated();
    expect($replyRes->json('data.parent_id'))->toBe($parentId);
    expect($replyRes->json('data.answer_to_id'))->toBe($parentId);

    // Fetch replies list
    $repliesRes = $this->actingAs($user, 'api')->getJson("/api/comments/{$parentId}/replies");
    $repliesRes->assertOk();
    $replies = collect($repliesRes->json('data'));
    $replyItem = $replies->firstWhere('id', $replyRes->json('data.id'));
    expect($replyItem)->not->toBeNull();
    expect($replyItem['answer_to_id'])->toBe($parentId);
});

test('point 48: hashtag page and follow/unfollow operations', function () {
    $user = User::factory()->create();

    $hashtag = Hashtag::create([
        'name' => 'Abidjan',
        'normalized_name' => 'abidjan',
        'posts_count' => 1,
        'followers_count' => 0,
    ]);

    $post = Post::create([
        'user_id' => $user->id,
        'body' => 'Magnifique journée à #Abidjan !',
        'visibility' => 'public',
        'status' => 'published',
    ]);
    $hashtag->posts()->attach($post->id);

    // 1. Hashtag page
    $resPage = $this->actingAs($user, 'api')->getJson('/api/hashtags/abidjan');
    $resPage->assertOk();
    expect($resPage->json('data.hashtag.name'))->toBe('Abidjan');
    expect($resPage->json('data.hashtag.posts_count'))->toBe(1);
    expect($resPage->json('data.hashtag.is_following'))->toBeFalse();

    // 2. Follow hashtag
    $resFollow = $this->actingAs($user, 'api')->postJson('/api/hashtags/abidjan/follow');
    $resFollow->assertOk();
    expect($resFollow->json('is_following'))->toBeTrue();
    expect($resFollow->json('followers_count'))->toBe(1);

    // Check on page after follow
    $resPageAfter = $this->actingAs($user, 'api')->getJson('/api/hashtags/abidjan');
    expect($resPageAfter->json('data.hashtag.is_following'))->toBeTrue();
    expect($resPageAfter->json('data.hashtag.followers_count'))->toBe(1);

    // 3. Unfollow hashtag
    $resUnfollow = $this->actingAs($user, 'api')->deleteJson('/api/hashtags/abidjan/follow');
    $resUnfollow->assertOk();
    expect($resUnfollow->json('is_following'))->toBeFalse();
    expect($resUnfollow->json('followers_count'))->toBe(0);

    // Check on search route alias as well
    $resSearch = $this->actingAs($user, 'api')->getJson('/api/search/hashtags/abidjan');
    $resSearch->assertOk();
    expect($resSearch->json('data.hashtag.is_following'))->toBeFalse();
});
