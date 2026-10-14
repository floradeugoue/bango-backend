<?php

use App\Models\Square\Hashtag;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Square\HashtagExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('rejects image upload exceeding 15MB with HTTP 422 validation error', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Create a fake image file > 15MB (e.g. 16MB)
    $oversizedFile = UploadedFile::fake()->create('heavy.jpg', 16 * 1024, 'image/jpeg');

    $response = postJson('/api/square-cr1/media/upload', [
        'file' => $oversizedFile,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('denies access to a post if the author has blocked the requester or requester blocked author', function () {
    $author = User::factory()->create();
    $requester = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post public de l auteur',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    Passport::actingAs($requester);

    // Initial access is allowed (200)
    getJson("/api/square-cr1/posts/{$post->id}")
        ->assertStatus(200);

    // Requester blocks author
    $requester->blockedUsers()->attach($author->id);

    getJson("/api/square-cr1/posts/{$post->id}")
        ->assertStatus(403);

    // Requester unblocks, but author blocks requester
    $requester->blockedUsers()->detach($author->id);
    $author->blockedUsers()->attach($requester->id);

    getJson("/api/square-cr1/posts/{$post->id}")
        ->assertStatus(403);
});

it('excludes blocked users posts from the discover feed', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $postA = Post::create([
        'user_id' => $userA->id,
        'body' => 'Post de User A',
        'audience' => 'public',
        'is_archived' => false,
    ]);
    $postB = Post::create([
        'user_id' => $userB->id,
        'body' => 'Post de User B',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    Passport::actingAs($userA);

    // Before block: both posts are in feed
    $resBefore = getJson('/api/square-cr1/square/feed?tab=discover');
    $resBefore->assertStatus(200);
    $idsBefore = collect($resBefore->json('data'))->pluck('id')->all();
    expect($idsBefore)->toContain((string) $postA->id)
        ->toContain((string) $postB->id);

    // User A blocks User B
    $userA->blockedUsers()->attach($userB->id);

    $resAfter = getJson('/api/square-cr1/square/feed?tab=discover');
    $resAfter->assertStatus(200);
    $idsAfter = collect($resAfter->json('data'))->pluck('id')->all();
    expect($idsAfter)->toContain((string) $postA->id)
        ->not->toContain((string) $postB->id);
});

it('returns empty feed in neighbourhood tab when user has neither city nor neighbourhood configured', function () {
    $userWithoutLocation = User::factory()->create([
        'city' => null,
        'neighbourhood' => null,
    ]);

    $postWithLocation = Post::create([
        'user_id' => User::factory()->create()->id,
        'body' => 'Post à Bonapriso',
        'place_city' => 'Douala',
        'place_neighbourhood' => 'Bonapriso',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    Passport::actingAs($userWithoutLocation);

    $response = getJson('/api/square-cr1/square/feed?tab=neighbourhood');
    $response->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('does not decrement hashtag posts_count below zero', function () {
    $user = User::factory()->create();
    $post = Post::create([
        'user_id' => $user->id,
        'body' => 'Post avec #bango',
        'audience' => 'public',
    ]);

    $hashtag = Hashtag::create([
        'name' => '#bango',
        'normalized_name' => 'bango',
        'posts_count' => 0,
    ]);

    $post->hashtags()->attach($hashtag->id);

    $service = app(HashtagExtractionService::class);
    $service->detachAll($post);

    $hashtag->refresh();
    expect($hashtag->posts_count)->toBe(0);
});

it('denies access to followers and following lists if target user blocked requester', function () {
    $target = User::factory()->create(['is_private' => false]);
    $requester = User::factory()->create();

    Passport::actingAs($requester);

    // Requester can view public target's followers
    getJson("/api/profile/{$target->id}/followers")
        ->assertStatus(200);

    // Target blocks requester
    $target->blockedUsers()->attach($requester->id);

    getJson("/api/profile/{$target->id}/followers")
        ->assertStatus(403);

    getJson("/api/profile/{$target->id}/following")
        ->assertStatus(403);
});
