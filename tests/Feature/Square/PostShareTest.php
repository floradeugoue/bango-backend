<?php

use App\Models\Square\Post;
use App\Models\Square\PostShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('permet a un utilisateur authentifie de partager une publication accessible et incremente shares_count', function () {
    $author = User::factory()->create();
    $sharer = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post Square à partager',
        'audience' => 'public',
        'allow_share' => true,
        'shares_count' => 0,
    ]);

    Passport::actingAs($sharer);

    $response = postJson("/api/square-cr1/posts/{$post->id}/share", [
        'channel' => 'whatsapp',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'shared' => true,
            'shares_count' => 1,
            'post_id' => $post->id,
            'deep_link' => "bango://post/{$post->id}",
        ]);

    expect($response->json('share_url'))->toContain("/posts/{$post->id}");

    $this->assertDatabaseHas('post_shares', [
        'post_id' => $post->id,
        'user_id' => $sharer->id,
        'channel' => 'whatsapp',
    ]);

    $post->refresh();
    expect($post->shares_count)->toBe(1);
});

it('renvoie 404 si la publication a partager est introuvable', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    postJson('/api/square-cr1/posts/999999/share')
        ->assertStatus(404);
});

it('refuse le partage si allow_share est desactive par lauteur', function () {
    $author = User::factory()->create();
    $sharer = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post non partageable',
        'audience' => 'public',
        'allow_share' => false,
    ]);

    Passport::actingAs($sharer);

    postJson("/api/square-cr1/posts/{$post->id}/share")
        ->assertStatus(403);

    expect(PostShare::count())->toBe(0);
});

it('refuse le partage si la publication est archivee', function () {
    $author = User::factory()->create();
    $sharer = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post archivé',
        'audience' => 'public',
        'allow_share' => true,
        'is_archived' => true,
    ]);

    Passport::actingAs($sharer);

    postJson("/api/square-cr1/posts/{$post->id}/share")
        ->assertStatus(403);

    expect(PostShare::count())->toBe(0);
});

it('refuse le partage si l utilisateur n a pas acces a la publication restreinte', function () {
    $author = User::factory()->create();
    $stranger = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post réservé aux followers',
        'audience' => 'followers',
        'allow_share' => true,
    ]);

    Passport::actingAs($stranger);

    postJson("/api/square-cr1/posts/{$post->id}/share")
        ->assertStatus(403);

    expect(PostShare::count())->toBe(0);
});

it('refuse le partage si l auteur ou l utilisateur a bloque lautre', function () {
    $author = User::factory()->create();
    $blocked = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post public',
        'audience' => 'public',
        'allow_share' => true,
    ]);

    $author->blockedUsers()->attach($blocked->id);

    Passport::actingAs($blocked);

    postJson("/api/square-cr1/posts/{$post->id}/share")
        ->assertStatus(403);

    expect(PostShare::count())->toBe(0);
});

it('est idempotent lors de retries avec idempotency_key sans doubler le compteur de partages', function () {
    $author = User::factory()->create();
    $sharer = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post Square',
        'audience' => 'public',
        'allow_share' => true,
        'shares_count' => 0,
    ]);

    Passport::actingAs($sharer);

    // 1ère requête avec clé d'idempotence
    $res1 = postJson("/api/square-cr1/posts/{$post->id}/share", [
        'channel' => 'native',
        'idempotency_key' => 'idemp-share-uuid-001',
    ]);

    $res1->assertStatus(200)
        ->assertJson(['shared' => true, 'shares_count' => 1]);

    // 2ème requête identique (retry réseau)
    $res2 = postJson("/api/square-cr1/posts/{$post->id}/share", [
        'channel' => 'native',
        'idempotency_key' => 'idemp-share-uuid-001',
    ]);

    $res2->assertStatus(200)
        ->assertJson(['shared' => true, 'shares_count' => 1]);

    expect(PostShare::where('post_id', $post->id)->where('user_id', $sharer->id)->count())->toBe(1);

    $post->refresh();
    expect($post->shares_count)->toBe(1);
});

it('permet a un utilisateur d effectuer des partages distincts successifs', function () {
    $author = User::factory()->create();
    $sharer = User::factory()->create();

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post Square populaire',
        'audience' => 'public',
        'allow_share' => true,
        'shares_count' => 0,
    ]);

    Passport::actingAs($sharer);

    // Partage 1 : WhatsApp
    postJson("/api/square-cr1/posts/{$post->id}/share", ['channel' => 'whatsapp'])
        ->assertStatus(200)
        ->assertJson(['shares_count' => 1]);

    // Partage 2 : Copie de lien
    postJson("/api/square-cr1/posts/{$post->id}/share", ['channel' => 'copy_link'])
        ->assertStatus(200)
        ->assertJson(['shares_count' => 2]);

    expect(PostShare::where('post_id', $post->id)->where('user_id', $sharer->id)->count())->toBe(2);

    $post->refresh();
    expect($post->shares_count)->toBe(2);
});
