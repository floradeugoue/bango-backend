<?php

use App\Models\Profile\Follow;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('retourne le fil Square paginé et masque les publications archivées', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Création de 3 posts publics actifs
    $p1 = Post::create(['user_id' => $user->id, 'body' => 'Post 1', 'audience' => 'public', 'is_archived' => false]);
    $p2 = Post::create(['user_id' => $user->id, 'body' => 'Post 2', 'audience' => 'public', 'is_archived' => false]);
    // Création d'un post archivé
    $pArchived = Post::create(['user_id' => $user->id, 'body' => 'Post archivé', 'audience' => 'public', 'is_archived' => true]);

    $response = getJson('/api/square-cr1/square/feed?tab=discover');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $p1->id)
        ->toContain((string) $p2->id)
        ->not->toContain((string) $pArchived->id);
});

it('filtre le fil sur les créateurs suivis dans l onglet following', function () {
    $me = User::factory()->create();
    $followed = User::factory()->create(['display_name' => 'Créateur Suivi']);
    $stranger = User::factory()->create(['display_name' => 'Inconnu']);

    Follow::create(['follower_id' => $me->id, 'following_id' => $followed->id]);

    $pFollowed = Post::create(['user_id' => $followed->id, 'body' => 'Post du créateur suivi', 'audience' => 'public']);
    $pStranger = Post::create(['user_id' => $stranger->id, 'body' => 'Post d un inconnu', 'audience' => 'public']);
    $pMine = Post::create(['user_id' => $me->id, 'body' => 'Mon propre post', 'audience' => 'public']);

    Passport::actingAs($me);

    $response = getJson('/api/square-cr1/square/feed?tab=following');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain((string) $pFollowed->id)
        ->toContain((string) $pMine->id)
        ->not->toContain((string) $pStranger->id);
});

it('filtre les publications par quartier ou ville dans l onglet neighbourhood', function () {
    $user = User::factory()->create(['city' => 'Douala', 'neighbourhood' => 'Bonapriso']);
    Passport::actingAs($user);

    $pNeighbour = Post::create([
        'user_id' => $user->id,
        'body' => 'Post Bonapriso',
        'place_city' => 'Douala',
        'place_neighbourhood' => 'Bonapriso',
        'audience' => 'public',
    ]);

    $pOtherCity = Post::create([
        'user_id' => $user->id,
        'body' => 'Post Bafoussam',
        'place_city' => 'Bafoussam',
        'place_neighbourhood' => 'Djeleng',
        'audience' => 'public',
    ]);

    $response = getJson('/api/square-cr1/square/feed?tab=neighbourhood');

    $response->assertStatus(200);
    $ids = collect($response->json('data'))->pluck('id')->all();

    expect($ids)->toContain((string) $pNeighbour->id)
        ->not->toContain((string) $pOtherCity->id);
});
