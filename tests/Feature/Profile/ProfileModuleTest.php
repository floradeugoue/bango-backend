<?php

use App\Models\Listing\Listing;
use App\Models\Profile\FollowRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('GET /api/profile returns authenticated user profile with stats and privacy', function () {
    $user = User::factory()->create([
        'handle' => 'monique.d',
        'display_name' => 'Monique Dupont',
        'bio' => 'Investisseur immobilier',
        'is_private' => false,
        'badge' => 'landlord',
    ]);

    Passport::actingAs($user);

    $response = getJson('/api/profile');

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.handle', 'monique.d')
        ->assertJsonPath('data.displayName', 'Monique Dupont')
        ->assertJsonPath('data.bio', 'Investisseur immobilier')
        ->assertJsonPath('data.isPrivate', false)
        ->assertJsonPath('data.badge', 'landlord')
        ->assertJsonPath('data.counts.followers', 0)
        ->assertJsonPath('data.counts.following', 0);
});

it('GET /api/profile/public/{identifier} returns public profile by ID and by @handle', function () {
    $alice = User::factory()->create([
        'handle' => 'alice.b',
        'display_name' => 'Alice Bango',
        'bio' => 'Bailleur certifié',
        'badge' => 'landlord',
        'is_private' => false,
    ]);

    $bob = User::factory()->create();
    Passport::actingAs($bob);

    // Fetch by numeric ID
    $resId = getJson("/api/profile/public/{$alice->id}");
    $resId->assertStatus(200)
        ->assertJsonPath('data.id', $alice->id)
        ->assertJsonPath('data.handle', 'alice.b')
        ->assertJsonPath('data.name', 'Alice Bango')
        ->assertJsonPath('data.badge', 'landlord')
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.isFollowing', false);

    // Fetch by @handle
    $resHandle = getJson('/api/profile/public/@alice.b');
    $resHandle->assertStatus(200)
        ->assertJsonPath('data.id', $alice->id)
        ->assertJsonPath('data.handle', 'alice.b');

    // Non-existent user returns 404
    $res404 = getJson('/api/profile/public/inconnu');
    $res404->assertStatus(404)->assertJsonPath('kind', 'not-found');
});

it('PUT /api/profile/identity updates handle, displayName and bio', function () {
    $user = User::factory()->create(['handle' => 'ancien.pseudo']);
    Passport::actingAs($user);

    $response = putJson('/api/profile/identity', [
        'handle' => 'nouveau.pseudo',
        'displayName' => 'Nouveau Nom',
        'bio' => 'Nouvelle présentation',
    ]);

    $response->assertStatus(200)->assertJson(['success' => true]);

    $user->refresh();
    expect($user->handle)->toBe('nouveau.pseudo')
        ->and($user->display_name)->toBe('Nouveau Nom')
        ->and($user->bio)->toBe('Nouvelle présentation');
});

it('PUT /api/profile/identity rejects handle already taken by another user', function () {
    User::factory()->create(['handle' => 'pris.deja']);
    $user = User::factory()->create(['handle' => 'mon.pseudo']);
    Passport::actingAs($user);

    $response = putJson('/api/profile/identity', [
        'handle' => 'pris.deja',
        'displayName' => 'Moi',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['handle']);
});

it('POST /api/profile/handle-check checks availability and reserved handles', function () {
    User::factory()->create(['handle' => 'deja.pris']);
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Free handle
    postJson('/api/profile/handle-check', ['handle' => 'pseudo.libre'])
        ->assertStatus(200)
        ->assertJson(['state' => 'free']);

    // Taken handle
    postJson('/api/profile/handle-check', ['handle' => 'deja.pris'])
        ->assertStatus(200)
        ->assertJsonPath('state', 'taken');

    // Reserved handle
    postJson('/api/profile/handle-check', ['handle' => 'admin'])
        ->assertStatus(200)
        ->assertJson(['state' => 'reserved']);
});

it('can follow, unfollow and toggle follow a public user', function () {
    $alice = User::factory()->create(['is_private' => false]);
    $bob = User::factory()->create(['is_private' => false]);

    Passport::actingAs($alice);

    // Cannot follow self
    postJson("/api/profile/{$alice->id}/follow")
        ->assertStatus(422);

    // Follow Bob
    postJson("/api/profile/{$bob->id}/follow")
        ->assertStatus(200)
        ->assertJson(['status' => 'followed']);

    expect($alice->isFollowing($bob))->toBeTrue();

    // Unfollow Bob
    deleteJson("/api/profile/{$bob->id}/follow")
        ->assertStatus(200)
        ->assertJson(['status' => 'unfollowed']);

    expect($alice->isFollowing($bob))->toBeFalse();

    // Toggle follow
    postJson("/api/profile/{$bob->id}/toggle-follow")
        ->assertStatus(200)
        ->assertJson(['status' => 'followed']);

    expect($alice->isFollowing($bob))->toBeTrue();
});

it('following a private user creates a pending request (PR-08)', function () {
    $privateUser = User::factory()->create(['is_private' => true]);
    $visitor = User::factory()->create();

    Passport::actingAs($visitor);

    // Send request
    $response = postJson("/api/profile/{$privateUser->id}/follow");
    $response->assertStatus(200)->assertJson(['status' => 'requested']);

    expect($visitor->isFollowing($privateUser))->toBeFalse()
        ->and($visitor->hasRequestedToFollow($privateUser))->toBeTrue();

    // Check request is visible in privateUser's follow requests
    Passport::actingAs($privateUser);
    $reqResponse = getJson('/api/profile/follow-requests');
    $reqResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $visitor->id);

    // Accept request
    postJson("/api/profile/follow-requests/{$visitor->id}/accept")
        ->assertStatus(200)->assertJson(['success' => true]);

    expect($visitor->isFollowing($privateUser))->toBeTrue()
        ->and($visitor->hasRequestedToFollow($privateUser))->toBeFalse();
});

it('rejecting a follow request removes it quietly', function () {
    $privateUser = User::factory()->create(['is_private' => true]);
    $visitor = User::factory()->create();

    Passport::actingAs($visitor);
    postJson("/api/profile/{$privateUser->id}/follow");

    Passport::actingAs($privateUser);
    postJson("/api/profile/follow-requests/{$visitor->id}/reject")
        ->assertStatus(200)->assertJson(['success' => true]);

    expect($visitor->isFollowing($privateUser))->toBeFalse()
        ->and(FollowRequest::count())->toBe(0);
});

it('switching account from private to public automatically accepts all pending requests', function () {
    $user = User::factory()->create(['is_private' => true]);
    $requester1 = User::factory()->create();
    $requester2 = User::factory()->create();

    FollowRequest::create(['from_id' => $requester1->id, 'to_id' => $user->id, 'status' => 'pending']);
    FollowRequest::create(['from_id' => $requester2->id, 'to_id' => $user->id, 'status' => 'pending']);

    Passport::actingAs($user);

    // Switch to public via privacy settings
    $res = putJson('/api/profile/privacy', [
        'privateAccount' => false,
    ]);

    $res->assertStatus(200)->assertJsonPath('privateAccount', false);

    $user->refresh();
    expect($user->is_private)->toBeFalse()
        ->and($requester1->isFollowing($user))->toBeTrue()
        ->and($requester2->isFollowing($user))->toBeTrue()
        ->and(FollowRequest::count())->toBe(0);
});

it('blocking a user severs all follows and requests both ways (PR-09)', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $user1->following()->attach($user2->id);
    $user2->following()->attach($user1->id);

    Passport::actingAs($user1);

    // Block user2
    postJson("/api/profile/{$user2->id}/block")
        ->assertStatus(200)->assertJson(['success' => true, 'blocked' => true]);

    expect($user1->isFollowing($user2))->toBeFalse()
        ->and($user2->isFollowing($user1))->toBeFalse()
        ->and($user1->hasBlocked($user2))->toBeTrue();

    // Unblock user2
    deleteJson("/api/profile/{$user2->id}/block")
        ->assertStatus(200)->assertJson(['success' => true, 'blocked' => false]);

    expect($user1->hasBlocked($user2))->toBeFalse();
});

it('POST /api/qr/resolve resolves bango://u/{handle} links and handles non-existent users', function () {
    $user = User::factory()->create([
        'handle' => 'nana.k',
        'display_name' => 'Nana Kouam',
        'badge' => 'agent',
    ]);

    // Unauthenticated or authenticated resolve
    $res = postJson('/api/qr/resolve', [
        'data' => 'bango://u/nana.k',
    ]);

    $res->assertStatus(200)
        ->assertJsonPath('id', $user->id)
        ->assertJsonPath('handle', 'nana.k')
        ->assertJsonPath('displayName', 'Nana Kouam')
        ->assertJsonPath('badge', 'agent')
        ->assertJsonPath('profileLink', 'bango://u/nana.k');

    // Unknown QR code
    postJson('/api/qr/resolve', ['data' => 'bango://u/inconnu'])
        ->assertStatus(404)
        ->assertJsonPath('kind', 'not-found');
});

it('PUT /api/profile/avatar replaces previous avatar file', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    Passport::actingAs($user);

    $file1 = UploadedFile::fake()->image('avatar1.jpg');
    $res1 = putJson('/api/profile/avatar', ['avatar' => $file1]);
    $res1->assertStatus(200)->assertJsonStructure(['avatarUrl']);

    $firstUrl = $res1->json('avatarUrl');

    $file2 = UploadedFile::fake()->image('avatar2.png');
    $res2 = putJson('/api/profile/avatar', ['avatar' => $file2]);
    $res2->assertStatus(200);

    $secondUrl = $res2->json('avatarUrl');
    expect($secondUrl)->not->toBe($firstUrl);
});

it('authenticates PR routes using real Passport Bearer access tokens', function () {
    $user = User::factory()->create([
        'handle' => 'passport.real',
        'display_name' => 'Passport Real User',
    ]);

    // 1. Unauthenticated request to protected PR route returns 401
    getJson('/api/profile')->assertStatus(401);

    // 2. Generate a real Passport Personal Access Token
    $tokenResult = $user->createToken('MobileApp');
    $accessToken = $tokenResult->accessToken;

    // 3. Make request using HTTP Authorization Bearer header
    $response = $this->withHeader('Authorization', 'Bearer '.$accessToken)
        ->getJson('/api/profile');

    $response->assertStatus(200)
        ->assertJsonPath('data.handle', 'passport.real')
        ->assertJsonPath('data.displayName', 'Passport Real User');

    // 4. Update profile using the real Bearer token
    $updateResponse = $this->withHeader('Authorization', 'Bearer '.$accessToken)
        ->putJson('/api/profile/identity', [
            'displayName' => 'Passport Updated',
            'handle' => 'passport.updated',
            'bio' => 'Passport Token Verified',
        ]);

    $updateResponse->assertStatus(200)->assertJson(['success' => true]);

    $user->refresh();
    expect($user->handle)->toBe('passport.updated')
        ->and($user->display_name)->toBe('Passport Updated');

    // 5. Revoking the token blocks subsequent requests with 401
    $tokenResult->token->revoke();
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$accessToken)
        ->getJson('/api/profile')
        ->assertStatus(401);
});

it('PUT /api/profile/identity updates website and renders it in GET /profile and public profile', function () {
    $user = User::factory()->create([
        'handle' => 'mon.site',
        'neighbourhood' => 'Akwa',
        'city' => 'Douala',
    ]);
    Passport::actingAs($user);

    // Rejet d'un website invalide (> 120 chars ou format non URL)
    $badUrl = putJson('/api/profile/identity', [
        'handle' => 'mon.site',
        'website' => 'not-a-valid-url',
    ]);
    $badUrl->assertStatus(422)->assertJsonValidationErrors(['website']);

    $tooLongUrl = putJson('/api/profile/identity', [
        'handle' => 'mon.site',
        'website' => 'https://example.com/'.str_repeat('a', 120),
    ]);
    $tooLongUrl->assertStatus(422)->assertJsonValidationErrors(['website']);

    // Succès avec une URL valide <= 120 caractères
    $validUrl = 'https://monportail-immo.cm/me';
    $response = putJson('/api/profile/identity', [
        'handle' => 'mon.site',
        'displayName' => 'Site Owner',
        'website' => $validUrl,
    ]);
    $response->assertStatus(200)->assertJson(['success' => true]);

    $user->refresh();
    expect($user->website)->toBe($validUrl);

    // Rendu dans GET /api/profile
    $getProfile = getJson('/api/profile');
    $getProfile->assertStatus(200)
        ->assertJsonPath('data.website', $validUrl);

    // Rendu dans profil public
    $publicProfile = getJson('/api/profile/public/mon.site');
    $publicProfile->assertStatus(200)
        ->assertJsonPath('data.website', $validUrl);
});

it('GET/PUT /api/profile/privacy handles showNeighbourhood and showFollowing (true by default)', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // GET par défaut : showNeighbourhood et showFollowing valent true
    $getRes = getJson('/api/profile/privacy');
    $getRes->assertStatus(200)
        ->assertJsonPath('showNeighbourhood', true)
        ->assertJsonPath('showFollowing', true);

    // PUT pour basculer les deux réglages
    $putRes = putJson('/api/profile/privacy', [
        'showNeighbourhood' => false,
        'showFollowing' => false,
    ]);
    $putRes->assertStatus(200)
        ->assertJsonPath('showNeighbourhood', false)
        ->assertJsonPath('showFollowing', false);

    $user->refresh();
    expect($user->privacy_settings['showNeighbourhood'])->toBeFalse()
        ->and($user->privacy_settings['showFollowing'])->toBeFalse();
});

it('masque le quartier sur le profil public quand showNeighbourhood est faux', function () {
    $user = User::factory()->create([
        'handle' => 'quartier.cache',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'privacy_settings' => [
            'showNeighbourhood' => false,
        ],
    ]);
    Passport::actingAs($user);

    $res = getJson('/api/profile/public/quartier.cache');
    $res->assertStatus(200)
        ->assertJsonPath('data.city', 'Douala')
        ->assertJsonPath('data.neighbourhood', null)
        ->assertJsonPath('data.place', 'Douala'); // Bonapriso ne doit pas apparaître dans place non plus
});

it('interdit l accès à /profile/{id}/following avec 403 following_hidden quand showFollowing est faux pour un tiers', function () {
    $target = User::factory()->create([
        'handle' => 'target.user',
        'privacy_settings' => [
            'showFollowing' => false,
        ],
    ]);
    $followed = User::factory()->create();
    $target->following()->attach($followed->id);

    $viewer = User::factory()->create();

    // 1. Un utilisateur tiers reçoit 403 {"kind":"following_hidden"}
    Passport::actingAs($viewer);
    $response = getJson("/api/profile/{$target->id}/following");
    $response->assertStatus(403)
        ->assertJsonPath('kind', 'following_hidden');

    // 2. Le titulaire du compte peut toujours voir sa propre liste d abonnements
    Passport::actingAs($target);
    $ownerResponse = getJson("/api/profile/{$target->id}/following");
    $ownerResponse->assertStatus(200);
    expect($ownerResponse->json('data'))->toHaveCount(1);
    expect($ownerResponse->json('data.0.id'))->toBe($followed->id);

    // 3. GET /api/profile/following pour le titulaire fonctionne également
    $ownerSelfResponse = getJson('/api/profile/following');
    $ownerSelfResponse->assertStatus(200);
    expect($ownerSelfResponse->json('data'))->toHaveCount(1);
});

it('GET /api/profile/{id}/listings/liked retourne les biens aimés selon la visibilité du profil', function () {
    $owner = User::factory()->create();
    $listing1 = Listing::factory()->published()->create(['owner_id' => $owner->id, 'title' => 'Villa Bonamoussadi']);
    $listing2 = Listing::factory()->published()->create(['owner_id' => $owner->id, 'title' => 'Studio Bali']);
    $savedListing = Listing::factory()->published()->create(['owner_id' => $owner->id, 'title' => 'Bien Secret Seulement Enregistre']);

    // Compte public avec 2 likes et 1 save
    $simpleUser = User::factory()->create(['is_private' => false]);
    $simpleUser->likedListings()->attach([$listing1->id, $listing2->id]);
    $simpleUser->savedListings()->attach([$savedListing->id]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    // 1. Consultation des biens aimés du compte public : retourne les 2 biens aimés, PAS le bien seulement enregistré
    $resPublic = getJson("/api/profile/{$simpleUser->id}/listings/liked");
    $resPublic->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta']);
    $data = $resPublic->json('data');
    expect($data)->toHaveCount(2);
    $titles = collect($data)->pluck('title');
    expect($titles)->toContain('Villa Bonamoussadi')
        ->and($titles)->toContain('Studio Bali')
        ->and($titles)->not->toContain('Bien Secret Seulement Enregistre');

    // 2. Compte privé non suivi : renvoie 403
    $privateUser = User::factory()->create(['is_private' => true]);
    $privateUser->likedListings()->attach([$listing1->id]);

    $resPrivate = getJson("/api/profile/{$privateUser->id}/listings/liked");
    $resPrivate->assertStatus(403);

    // 3. Compte privé suivi par le viewer : renvoie 200 avec le contenu
    $viewer->following()->attach($privateUser->id);
    $resFollowed = getJson("/api/profile/{$privateUser->id}/listings/liked");
    $resFollowed->assertStatus(200);
    expect($resFollowed->json('data'))->toHaveCount(1);
    expect($resFollowed->json('data.0.title'))->toBe('Villa Bonamoussadi');

    // 4. Le titulaire du compte privé peut toujours voir ses propres biens aimés
    Passport::actingAs($privateUser);
    $resSelf = getJson("/api/profile/{$privateUser->id}/listings/liked");
    $resSelf->assertStatus(200);
    expect($resSelf->json('data'))->toHaveCount(1);
});
