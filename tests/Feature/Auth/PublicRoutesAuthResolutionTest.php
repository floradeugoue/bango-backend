<?php

use App\Models\Listing\Listing;
use App\Models\Listing\ListingLike;
use App\Models\Listing\ListingSave;
use App\Models\Square\Post;
use App\Models\Square\PostLike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('public route /listings recognizes authenticated user with bearer token for is_liked and is_saved', function () {
    $user = User::factory()->create();
    $listing = Listing::factory()->create([
        'status' => 'published',
    ]);

    // L'utilisateur aime et sauvegarde le bien
    ListingLike::create(['listing_id' => $listing->id, 'user_id' => $user->id]);
    ListingSave::create(['listing_id' => $listing->id, 'user_id' => $user->id]);

    // 1. Sans token : is_liked et is_saved valent false
    $responseGuest = $this->getJson('/api/listings');
    $responseGuest->assertStatus(200);
    $itemGuest = collect($responseGuest->json('data'))->firstWhere('id', $listing->id);
    expect($itemGuest['is_liked'])->toBeFalse()
        ->and($itemGuest['is_saved'])->toBeFalse();

    // 2. Avec token Bearer (via actingAs Passport) sur la même route publique
    $responseAuth = $this->actingAs($user, 'api')->getJson('/api/listings');
    $responseAuth->assertStatus(200);
    $itemAuth = collect($responseAuth->json('data'))->firstWhere('id', $listing->id);
    expect($itemAuth['is_liked'])->toBeTrue()
        ->and($itemAuth['is_saved'])->toBeTrue();
});

test('public detail /posts/{id} recognizes author and liked state with bearer token', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Mon post de test',
        'audience' => 'public',
        'language' => 'fr',
    ]);

    PostLike::create(['post_id' => $post->id, 'user_id' => $author->id]);

    // 1. Sans token : mine: false, liked: false
    $guestResponse = $this->getJson("/api/posts/{$post->id}");
    $guestResponse->assertStatus(200);
    expect($guestResponse->json('data.mine'))->toBeFalse()
        ->and($guestResponse->json('data.liked'))->toBeFalse();

    // 2. Avec token de l'auteur : mine: true, liked: true
    $authResponse = $this->actingAs($author, 'api')->getJson("/api/posts/{$post->id}");
    $authResponse->assertStatus(200);
    expect($authResponse->json('data.mine'))->toBeTrue()
        ->and($authResponse->json('data.liked'))->toBeTrue();
});
