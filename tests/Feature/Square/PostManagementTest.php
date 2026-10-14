<?php

use App\Models\Square\Hashtag;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('permet à l auteur de modifier sa légende et met à jour is_edited et les hashtags', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Légende initiale #Ancien',
        'audience' => 'public',
    ]);

    // Simuler le hashtag initial
    $oldTag = Hashtag::create(['name' => 'Ancien', 'normalized_name' => 'ancien', 'posts_count' => 1]);
    $post->hashtags()->attach($oldTag->id);

    Passport::actingAs($author);

    $response = patchJson("/api/square-cr1/posts/{$post->id}/caption", [
        'caption' => 'Nouvelle légende mise à jour #Nouveau',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.body', 'Nouvelle légende mise à jour #Nouveau')
        ->assertJsonPath('data.edited', true);

    $this->assertDatabaseHas('posts', [
        'id' => $post->id,
        'body' => 'Nouvelle légende mise à jour #Nouveau',
        'is_edited' => true,
    ]);

    // L'ancien hashtag doit avoir été décrémenté et le nouveau créé
    expect(Hashtag::where('normalized_name', 'ancien')->value('posts_count'))->toBe(0);
    expect(Hashtag::where('normalized_name', 'nouveau')->value('posts_count'))->toBe(1);
});

it('permet à l auteur de changer l audience de sa publication', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Mon post',
        'audience' => 'public',
    ]);

    Passport::actingAs($author);

    $response = patchJson("/api/square-cr1/posts/{$post->id}/audience", [
        'audience' => 'followers',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.audience', 'followers');

    $this->assertDatabaseHas('posts', [
        'id' => $post->id,
        'audience' => 'followers',
    ]);
});

it('permet à l auteur de fermer ou réouvrir les commentaires', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Mon post',
        'comments_closed' => false,
    ]);

    Passport::actingAs($author);

    $response = patchJson("/api/square-cr1/posts/{$post->id}/comments-status", [
        'comments_closed' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.commentsClosed', true);

    $this->assertDatabaseHas('posts', [
        'id' => $post->id,
        'comments_closed' => true,
    ]);
});

it('permet à l auteur d archiver et désarchiver sa publication', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post à archiver',
        'is_archived' => false,
    ]);

    Passport::actingAs($author);

    // 1. Archivage
    $resArchive = postJson("/api/square-cr1/posts/{$post->id}/archive");
    $resArchive->assertStatus(200)
        ->assertJsonPath('data.is_archived', true);

    $this->assertDatabaseHas('posts', [
        'id' => $post->id,
        'is_archived' => true,
    ]);

    // 2. Désarchivage
    $resUnarchive = postJson("/api/square-cr1/posts/{$post->id}/unarchive");
    $resUnarchive->assertStatus(200)
        ->assertJsonPath('data.is_archived', false);

    $this->assertDatabaseHas('posts', [
        'id' => $post->id,
        'is_archived' => false,
    ]);
});

it('permet à l auteur de supprimer sa publication', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post à supprimer #Bye',
    ]);

    $tag = Hashtag::create(['name' => 'Bye', 'normalized_name' => 'bye', 'posts_count' => 1]);
    $post->hashtags()->attach($tag->id);

    Passport::actingAs($author);

    $response = deleteJson("/api/square-cr1/posts/{$post->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    // Vérifier suppression (soft delete)
    $this->assertSoftDeleted('posts', ['id' => $post->id]);

    // Vérifier décrémentation du hashtag
    expect(Hashtag::where('normalized_name', 'bye')->value('posts_count'))->toBe(0);
});

it('interdit à un utilisateur tiers de modifier, archiver ou supprimer le post d un autre', function () {
    $author = User::factory()->create();
    $otherUser = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post de l auteur',
        'audience' => 'public',
    ]);

    Passport::actingAs($otherUser);

    // Tentative de modification de légende -> 403
    patchJson("/api/square-cr1/posts/{$post->id}/caption", ['caption' => 'Piratage'])
        ->assertStatus(403);

    // Tentative de modification d'audience -> 403
    patchJson("/api/square-cr1/posts/{$post->id}/audience", ['audience' => 'followers'])
        ->assertStatus(403);

    // Tentative d'archivage -> 403
    postJson("/api/square-cr1/posts/{$post->id}/archive")
        ->assertStatus(403);

    // Tentative de suppression -> 403
    deleteJson("/api/square-cr1/posts/{$post->id}")
        ->assertStatus(403);
});
