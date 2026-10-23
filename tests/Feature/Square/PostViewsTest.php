<?php

use App\Models\Square\Post;
use App\Models\Square\PostView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

function makePublicPost(): Post
{
    $author = User::factory()->create(['status' => 'active']);

    return Post::create([
        'user_id' => $author->id,
        'body' => 'Bonjour',
        'audience' => 'public',
    ]);
}

it('requires authentication to record a post view', function () {
    $post = makePublicPost();

    $this->postJson("/api/posts/{$post->id}/view")->assertStatus(401);
});

it('counts one view per account within 24 hours', function () {
    $post = makePublicPost();
    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $this->postJson("/api/posts/{$post->id}/view")
        ->assertOk()
        ->assertJsonPath('data.viewed', true)
        ->assertJsonPath('data.views_count', 1);

    $this->postJson("/api/posts/{$post->id}/view")
        ->assertOk()
        ->assertJsonPath('data.viewed', false)
        ->assertJsonPath('data.views_count', 1);

    expect(PostView::count())->toBe(1);
});

it('counts a new view after 24 hours and from other accounts', function () {
    $post = makePublicPost();
    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $this->postJson("/api/posts/{$post->id}/view")->assertJsonPath('data.views_count', 1);

    PostView::query()->update(['viewed_at' => now()->subHours(25)]);

    $this->postJson("/api/posts/{$post->id}/view")
        ->assertJsonPath('data.viewed', true)
        ->assertJsonPath('data.views_count', 2);

    Passport::actingAs(User::factory()->create(['status' => 'active']));

    $this->postJson("/api/square-cr1/posts/{$post->id}/view")
        ->assertJsonPath('data.viewed', true)
        ->assertJsonPath('data.views_count', 3);
});

it('exposes views_count in the post resource', function () {
    $post = makePublicPost();
    $post->update(['views_count' => 7]);
    Passport::actingAs(User::factory()->create(['status' => 'active']));

    $this->getJson("/api/posts/{$post->id}")
        ->assertOk()
        ->assertJsonPath('data.views_count', 7);
});
