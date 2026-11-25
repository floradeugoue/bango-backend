<?php

use App\Enums\Reel\ReelStatus;
use App\Enums\Square\PostAudience;
use App\Enums\Square\PostMediaKind;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public post displays open graph tags and preview', function () {
    $author = User::factory()->create([
        'name' => 'Floriane Deugoue',
        'handle' => 'floriane',
        'is_private' => false,
    ]);

    $post = Post::factory()->create([
        'user_id' => $author->id,
        'body' => 'Bonjour le quartier ! Première publication officielle sur BANGO Square.',
        'audience' => PostAudience::Public,
        'is_archived' => false,
    ]);

    PostMedia::create([
        'post_id' => $post->id,
        'media_type' => PostMediaKind::Photo,
        'media_url' => 'https://cdn.bangoapp.com/square/images/sample.jpg',
        'mime_type' => 'image/jpeg',
        'display_order' => 1,
    ]);

    $response = $this->get('/p/'.$post->id);

    $response->assertOk();
    $response->assertSee('Floriane Deugoue', false);
    $response->assertSee('Bonjour le quartier !', false);
    $response->assertSee('https://cdn.bangoapp.com/square/images/sample.jpg', false);
    $response->assertSee('property="og:image"', false);
    $response->assertSee('name="twitter:card" content="summary_large_image"', false);
    $response->assertSee('bango://post?id='.$post->id, false);
    $response->assertSee('https://play.google.com/store/apps/details?id=com.bangoapp.app', false);
});

test('private or deleted post renders generic fallback without leaking content', function () {
    $author = User::factory()->create(['is_private' => true]);

    $privatePost = Post::factory()->create([
        'user_id' => $author->id,
        'body' => 'Secret message that should never be shown in public preview',
        'audience' => PostAudience::Followers,
    ]);

    PostMedia::create([
        'post_id' => $privatePost->id,
        'media_type' => PostMediaKind::Photo,
        'media_url' => 'https://cdn.bangoapp.com/square/images/secret.jpg',
        'mime_type' => 'image/jpeg',
        'display_order' => 1,
    ]);

    $response = $this->get('/p/'.$privatePost->id);

    $response->assertOk();
    $response->assertSee('Publication indisponible', false);
    $response->assertDontSee('Secret message that should never be shown', false);
    $response->assertDontSee('secret.jpg', false);

    // Non-existent post
    $missingResponse = $this->get('/p/999999999');
    $missingResponse->assertOk();
    $missingResponse->assertSee('Publication indisponible', false);
});

test('ready reel renders open graph tags and preview', function () {
    $author = User::factory()->create([
        'name' => 'Marc Dupont',
        'handle' => 'marc_d',
        'is_private' => false,
    ]);

    $reel = Reel::factory()->create([
        'user_id' => $author->id,
        'caption' => 'Superbe visite de la villa à Akwa ! Regardez cette vue.',
        'status' => ReelStatus::Ready,
        'thumbnail_url' => 'https://cdn.bangoapp.com/reels/covers/akwa.jpg',
    ]);

    $response = $this->get('/r/'.$reel->id);

    $response->assertOk();
    $response->assertSee('Marc Dupont', false);
    $response->assertSee('Superbe visite de la villa à Akwa', false);
    $response->assertSee('https://cdn.bangoapp.com/reels/covers/akwa.jpg', false);
    $response->assertSee('bango://reel?id='.$reel->id, false);
});

test('draft or deleted reel displays generic fallback', function () {
    $author = User::factory()->create(['is_private' => false]);

    $draftReel = Reel::factory()->draft()->create([
        'user_id' => $author->id,
        'caption' => 'Confidential video reel not ready',
    ]);

    $response = $this->get('/r/'.$draftReel->id);

    $response->assertOk();
    $response->assertSee('Reel indisponible', false);
    $response->assertDontSee('Confidential video reel not ready', false);
});

test('published listing renders open graph tags and price preview', function () {
    $author = User::factory()->create([
        'name' => 'Agence Immobilière Prestige',
        'handle' => 'prestige_immo',
    ]);

    $listing = Listing::factory()->published()->create([
        'owner_id' => $author->id,
        'created_by_id' => $author->id,
        'title' => 'Magnifique Duplex 4 pièces Akwa',
        'description' => 'Superbe opportunité de logement spacieux et lumineux au cœur de la ville.',
        'price' => 250000,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);

    $listing->photos()->create([
        'type' => 'photo',
        'path' => 'listings/photo1.jpg',
        'url' => 'https://cdn.bangoapp.com/listings/photo1.jpg',
        'order' => 1,
    ]);

    $response = $this->get('/l/'.$listing->id);

    $response->assertOk();
    $response->assertSee('Magnifique Duplex 4 pièces Akwa', false);
    $response->assertSee('250 000 XAF', false);
    $response->assertSee('Akwa, Douala', false);
    $response->assertSee('https://cdn.bangoapp.com/listings/photo1.jpg', false);
    $response->assertSee('bango://listing?id='.$listing->id, false);
});

test('draft or taken listing displays generic fallback', function () {
    $listing = Listing::factory()->draft()->create([
        'title' => 'Secret unreleased draft listing',
    ]);

    $response = $this->get('/l/'.$listing->id);

    $response->assertOk();
    $response->assertSee('Annonce indisponible', false);
    $response->assertDontSee('Secret unreleased draft listing', false);
});

test('public user profile renders open graph tags and preview', function () {
    $user = User::factory()->create([
        'name' => 'Paul Biya',
        'handle' => 'paulbiya',
        'bio' => 'Habitant passionné de Yaoundé et créateur de contenu sur BANGO.',
        'avatar_url' => 'https://cdn.bangoapp.com/avatars/paul.jpg',
        'is_private' => false,
    ]);

    $responseAt = $this->get('/@paulbiya');
    $responseAt->assertOk();
    $responseAt->assertSee('Paul Biya (@paulbiya) sur BANGO', false);
    $responseAt->assertSee('Habitant passionné de Yaoundé', false);
    $responseAt->assertSee('https://cdn.bangoapp.com/avatars/paul.jpg', false);
    $responseAt->assertSee('bango://u/paulbiya', false);

    $responseShort = $this->get('/u/paulbiya');
    $responseShort->assertOk();
    $responseShort->assertSee('Paul Biya (@paulbiya) sur BANGO', false);
});

test('private or deleted user profile renders generic fallback', function () {
    $user = User::factory()->create([
        'handle' => 'secret_agent',
        'is_private' => true,
        'bio' => 'Top secret information',
    ]);

    $response = $this->get('/@secret_agent');
    $response->assertOk();
    $response->assertSee('Ce profil est privé ou indisponible sur BANGO', false);
    $response->assertDontSee('Top secret information', false);
});

test('assetlinks.json returns valid android digital asset links configuration', function () {
    $response = $this->get('/.well-known/assetlinks.json');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');
    $data = $response->json();

    expect($data)->toBeArray();
    expect($data[0]['relation'])->toContain('delegate_permission/common.handle_all_urls');
    expect($data[0]['target']['package_name'])->toBe('com.bangoapp.app');
});

test('apple-app-site-association returns valid ios universal links configuration', function () {
    $response = $this->get('/.well-known/apple-app-site-association');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');
    $data = $response->json();

    expect($data)->toBeArray();
    expect($data)->toHaveKey('applinks');
    expect($data['applinks']['details'][0]['components'])->toBeArray();
});
