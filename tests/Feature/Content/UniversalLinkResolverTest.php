<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
use App\Enums\Square\PostAudience;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Support\Report;
use App\Models\User;
use App\Services\Content\LinkResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->author = User::factory()->create([
        'handle' => 'author_bango',
        'status' => 'active',
    ]);
});

test('resolves a valid public square post directly and via short link', function () {
    $post = Post::create([
        'user_id' => $this->author->id,
        'body' => 'Belle journée à Akwa ! #douala',
        'audience' => PostAudience::Public,
    ]);

    // 1. Résolution par chemin direct (/posts/{id})
    $response = $this->getJson("/api/content/resolve-link?path=/posts/{$post->id}");
    $response->assertOk()
        ->assertJsonPath('data.is_valid', true)
        ->assertJsonPath('data.target_type', 'post')
        ->assertJsonPath('data.target_id', (string) $post->id)
        ->assertJsonPath('data.status', 'available')
        ->assertJsonPath('data.requires_auth', false)
        ->assertJsonPath('data.is_accessible', true)
        ->assertJsonPath('data.metadata.author_handle', 'author_bango');

    // 2. Création et résolution par lien court (/r/{code})
    $service = app(LinkResolverService::class);
    $shortLink = $service->createShortLink('post', $post->id, $this->author);

    $shortResponse = $this->getJson("/api/content/resolve-link?path=/r/{$shortLink->code}");
    $shortResponse->assertOk()
        ->assertJsonPath('data.target_type', 'post')
        ->assertJsonPath('data.target_id', (string) $post->id)
        ->assertJsonPath('data.short_link.code', $shortLink->code)
        ->assertJsonPath('data.short_link.clicks_count', 1);

    expect($shortLink->fresh()->clicks_count)->toBe(1);
});

test('returns 410 Gone when resolving a post deleted by its author', function () {
    $post = Post::create([
        'user_id' => $this->author->id,
        'body' => 'Post bientôt supprimé',
        'audience' => PostAudience::Public,
    ]);

    $post->delete(); // Soft delete par l'auteur

    $response = $this->getJson("/api/content/resolve-link?path=/posts/{$post->id}");
    $response->assertStatus(410)
        ->assertJsonPath('error', 'POST_GONE')
        ->assertJsonPath('resource_type', 'post')
        ->assertJsonPath('resource_id', (string) $post->id);
});

test('returns 423 Locked when resolving a post locked by moderation', function () {
    $post = Post::create([
        'user_id' => $this->author->id,
        'body' => 'Contenu sous enquête',
        'audience' => PostAudience::Public,
    ]);

    // Signalement résolu avec sanction de masquage
    Report::create([
        'reporter_id' => User::factory()->create()->id,
        'reportable_type' => 'post',
        'reportable_id' => (string) $post->id,
        'reason' => 'spam',
        'status' => 'resolved',
        'action_taken' => 'blocked',
    ]);

    $response = $this->getJson("/api/content/resolve-link?path=/posts/{$post->id}");
    $response->assertStatus(423)
        ->assertJsonPath('error', 'POST_LOCKED')
        ->assertJsonPath('resource_type', 'post');
});

test('resolves a video reel with correct status and 410 on deletion', function () {
    $reel = Reel::create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->author->id,
        'caption' => 'Reel découverte des saveurs',
        'status' => 'ready',
        'video_url' => 'https://stream.bango.africa/video.mp4',
        'thumbnail_url' => 'https://stream.bango.africa/thumb.jpg',
    ]);

    $response = $this->getJson("/api/content/resolve-link?path=/reels/{$reel->id}");
    $response->assertOk()
        ->assertJsonPath('data.target_type', 'reel')
        ->assertJsonPath('data.target_id', $reel->id)
        ->assertJsonPath('data.status', 'available');

    $reel->delete();

    $this->getJson("/api/content/resolve-link?path=/reels/{$reel->id}")
        ->assertStatus(410)
        ->assertJsonPath('error', 'REEL_GONE');
});

test('resolves a real estate listing without exposing confidential address', function () {
    $listing = Listing::create([
        'id' => (string) Str::uuid(),
        'owner_id' => $this->author->id,
        'created_by_id' => $this->author->id,
        'title' => 'Studio Meublé Bonapriso',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'location' => 'Près du glacier moderne', // Repère public
        'address' => '12 Rue des Palmiers, Sonnette 4B, Code 9921', // Adresse confidentielle
        'price' => 150000,
        'currency' => 'XAF',
        'status' => 'published',
        'kind' => 'studio',
        'mode' => 'rent',
    ]);

    $response = $this->getJson("/api/content/resolve-link?path=/listings/{$listing->id}");
    $response->assertOk()
        ->assertJsonPath('data.target_type', 'listing')
        ->assertJsonPath('data.metadata.title', 'Studio Meublé Bonapriso')
        ->assertJsonPath('data.metadata.indicative_location', 'Près du glacier moderne')
        ->assertJsonMissing(['address' => '12 Rue des Palmiers, Sonnette 4B, Code 9921']); // Strictement confidentiel

    // Si le bien passe au statut taken (loué)
    $listing->update(['status' => 'taken']);
    $takenResponse = $this->getJson("/api/content/resolve-link?path=/listings/{$listing->id}");
    $takenResponse->assertOk()
        ->assertJsonPath('data.status', 'taken')
        ->assertJsonPath('data.metadata.is_taken', true);

    // Si le bien est supprimé
    $listing->delete();
    $this->getJson("/api/content/resolve-link?path=/listings/{$listing->id}")
        ->assertStatus(410)
        ->assertJsonPath('error', 'LISTING_GONE');
});

test('resolves a user profile and distinguishes deleted and suspended accounts', function () {
    $user = User::factory()->create([
        'handle' => 'active_user',
        'status' => 'active',
    ]);

    // Résolution via handle avec arobase ou sans
    $this->getJson('/api/content/resolve-link?path=/@active_user')
        ->assertOk()
        ->assertJsonPath('data.target_type', 'user')
        ->assertJsonPath('data.metadata.handle', 'active_user');

    // Compte suspendu par la modération
    $user->update(['status' => 'suspended']);
    $this->getJson('/api/content/resolve-link?path=/@active_user')
        ->assertStatus(423)
        ->assertJsonPath('error', 'USER_LOCKED');

    // Compte supprimé
    $user->update(['status' => 'active']);
    $user->delete();
    $this->getJson('/api/content/resolve-link?path=/@active_user')
        ->assertStatus(410)
        ->assertJsonPath('error', 'USER_GONE');
});

test('resolves a professional page and distinguishes deleted and suspended pages', function () {
    $page = Page::create([
        'owner_id' => $this->author->id,
        'name' => 'Agence Immobilière Océan',
        'handle' => 'agence_ocean',
        'sector' => PageSector::RealEstateAgency->value,
        'certification_status' => PageCertificationStatus::Approved->value,
        'status' => PageStatus::Active->value,
    ]);

    $this->getJson('/api/content/resolve-link?path=/pages/agence_ocean')
        ->assertOk()
        ->assertJsonPath('data.target_type', 'page')
        ->assertJsonPath('data.metadata.handle', 'agence_ocean')
        ->assertJsonPath('data.metadata.sector', PageSector::RealEstateAgency->value);

    // Page suspendue
    $page->update(['status' => PageStatus::Suspended->value]);
    $this->getJson('/api/content/resolve-link?path=/pages/agence_ocean')
        ->assertStatus(423)
        ->assertJsonPath('error', 'PAGE_LOCKED');

    // Page supprimée
    $page->update(['status' => PageStatus::Active->value]);
    $page->delete();
    $this->getJson('/api/content/resolve-link?path=/pages/agence_ocean')
        ->assertStatus(410)
        ->assertJsonPath('error', 'PAGE_GONE');
});

test('returns 404 for malformed path or unknown short link code', function () {
    $this->getJson('/api/content/resolve-link?path=/r/unknown999')
        ->assertStatus(404)
        ->assertJsonPath('error', 'SHORT_LINK_NOT_FOUND');

    $this->getJson('/api/content/resolve-link?path=invalid_unknown_syntax')
        ->assertStatus(404)
        ->assertJsonPath('error', 'INVALID_LINK_FORMAT');
});
