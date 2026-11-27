<?php

use App\Enums\Square\PostAudience;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Notification\DeviceToken;
use App\Models\Square\Post;
use App\Models\User;
use App\Notifications\Profile\ConfirmNeighbourhoodNotification;
use App\Services\Notification\PushNotificationService;
use Database\Seeders\GeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

// =========================================================================
// 1. COORDONNÉES DES QUARTIERS (GeoSeeder & Backfill Command)
// =========================================================================

it('seeds lat/lng coordinates for all neighbourhoods in open cities', function () {
    $this->seed(GeoSeeder::class);

    $missing = Neighbourhood::query()
        ->whereHas('city', fn ($q) => $q->where('is_active', true))
        ->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng'))
        ->count();

    expect($missing)->toBe(0);

    $bonapriso = Neighbourhood::where('slug', 'bonapriso')->firstOrFail();
    expect($bonapriso->lat)->toBe(4.0256)
        ->and($bonapriso->lng)->toBe(9.7022);

    $cocody = Neighbourhood::where('slug', 'cocody')->firstOrFail();
    expect($cocody->lat)->not->toBeNull()
        ->and($cocody->lng)->not->toBeNull();
});

it('backfills missing neighbourhood coordinates from catalog and optional nominatim with city proximity guard', function () {
    $currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $country = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id, 'is_active' => true]);
    $douala = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $country->id, 'lat' => 4.0511, 'lng' => 9.7679, 'is_active' => true]);

    $logpom = Neighbourhood::create(['name' => 'Logpom', 'slug' => 'logpom', 'city_id' => $douala->id, 'lat' => null, 'lng' => null]);
    $customClose = Neighbourhood::create(['name' => 'Quartier Proche', 'slug' => 'quartier-proche', 'city_id' => $douala->id, 'lat' => null, 'lng' => null]);
    $customFarHomonym = Neighbourhood::create(['name' => 'Quartier Homonyme', 'slug' => 'quartier-homonyme', 'city_id' => $douala->id, 'lat' => null, 'lng' => null]);

    Http::fake([
        'https://nominatim.openstreetmap.org/search*' => function ($request) {
            $q = (string) ($request->data()['q'] ?? '');
            if (str_contains($q, 'Quartier Proche')) {
                return Http::response([['lat' => '4.0600', 'lon' => '9.7400']], 200);
            }

            // Homonyme situé à plus de 40 km de Douala (doit être rejeté)
            return Http::response([['lat' => '10.7424', 'lon' => '13.8023']], 200);
        },
    ]);

    // Dry-run ne modifie rien
    artisan('geo:backfill-neighbourhood-coords', [
        '--only-active-cities' => true,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($logpom->fresh()->lat)->toBeNull();

    // Exécution réelle avec Nominatim
    artisan('geo:backfill-neighbourhood-coords', [
        '--only-active-cities' => true,
        '--nominatim' => true,
        '--sleep' => 0,
    ])->assertSuccessful();

    $logpom->refresh();
    $customClose->refresh();
    $customFarHomonym->refresh();

    expect($logpom->lat)->toBe(4.0875)
        ->and($logpom->lng)->toBe(9.7664)
        ->and($customClose->lat)->toBe(4.06)
        ->and($customClose->lng)->toBe(9.74)
        ->and($customFarHomonym->lat)->toBeNull()
        ->and($customFarHomonym->lng)->toBeNull();
});

// =========================================================================
// 2. PREUVE DE POSITION SUR PUT /api/profile ET PUT /api/profile/location
// =========================================================================

it('requires authentication on PUT /api/profile and PUT /api/profile/location', function () {
    putJson('/api/profile', ['city' => 'Douala'])->assertUnauthorized();
    putJson('/api/profile/location', ['city' => 'Douala'])->assertUnauthorized();
});

it('verifies neighbourhood within 3 km on PUT /api/profile, records neighbourhood_verified_at, and never stores coordinates', function () {
    $this->seed(GeoSeeder::class);

    $user = User::factory()->create();
    Passport::actingAs($user);

    // Bonapriso center = (4.0256, 9.7022). Décalage de +0.015° lat ≈ 1.67 km (<= 3 km).
    $response = putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 4.0406,
        'longitude' => 9.7022,
        'accuracy' => 24.5,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('user.city', 'Douala')
        ->assertJsonPath('user.neighbourhood', 'Bonapriso')
        ->assertJsonPath('user.neighbourhood_verified', true)
        ->assertJsonPath('user.neighbourhoodVerified', true)
        ->assertJsonPath('user.needs_neighbourhood_verification', false);

    expect($response->json('user.neighbourhood_verified_at'))->not->toBeNull();

    $user->refresh();
    expect($user->cityName())->toBe('Douala')
        ->and($user->neighbourhoodName())->toBe('Bonapriso')
        ->and($user->neighbourhood_verified_at)->not->toBeNull();

    // Aucune coordonnée GPS n'est stockée dans la table users
    expect(Schema::hasColumn('users', 'latitude'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'longitude'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'accuracy'))->toBeFalse();
});

it('accepts position just under 3 km and rejects position over 3 km with 422 NEIGHBOURHOOD_NOT_VERIFIED', function () {
    $this->seed(GeoSeeder::class);

    $user = User::factory()->create();
    Passport::actingAs($user);

    // Bonapriso center = (4.0256, 9.7022).
    // +0.029° lat ≈ 3.22 km (> 3 km) -> refus 422 NEIGHBOURHOOD_NOT_VERIFIED
    $tooFar = putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 4.0546,
        'longitude' => 9.7022,
        'accuracy' => 15,
    ]);

    $tooFar->assertStatus(422)
        ->assertJsonPath('code', 'NEIGHBOURHOOD_NOT_VERIFIED')
        ->assertJsonPath('error', 'NEIGHBOURHOOD_NOT_VERIFIED')
        ->assertJsonValidationErrors(['neighbourhood']);

    $user->refresh();
    expect($user->neighbourhoodName())->toBeNull()
        ->and($user->neighbourhood_verified_at)->toBeNull();

    // +0.0265° lat ≈ 2.95 km (<= 3 km) -> accepté
    $within3Km = putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 4.0521,
        'longitude' => 9.7022,
        'accuracy' => 15,
    ]);

    $within3Km->assertOk()
        ->assertJsonPath('user.neighbourhood', 'Bonapriso')
        ->assertJsonPath('user.neighbourhood_verified', true);
});

it('rejects requests without coordinates, unknown neighbourhoods, city mismatch, or neighbourhoods without coordinates with 422 NEIGHBOURHOOD_NOT_VERIFIED', function () {
    $this->seed(GeoSeeder::class);

    $douala = City::where('slug', 'douala')->firstOrFail();
    $noCoordsNeighbourhood = Neighbourhood::create([
        'city_id' => $douala->id,
        'name' => 'Sans Coordonnees',
        'slug' => 'sans-coordonnees',
        'lat' => null,
        'lng' => null,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Passport::actingAs($user);

    // 1. Sans coordonnées : PUT /api/profile/location enregistre le quartier comme non vérifié (preuve GPS facultative)
    putJson('/api/profile/location', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ])->assertOk()
        ->assertJsonPath('user.neighbourhood', 'Bonapriso')
        ->assertJsonPath('user.neighbourhood_verified', false)
        ->assertJsonPath('user.neighbourhoodVerified', false);

    // 2. Sans coordonnées : PUT /api/profile enregistre également le quartier comme non vérifié
    putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ])->assertOk()
        ->assertJsonPath('user.neighbourhood', 'Akwa')
        ->assertJsonPath('user.neighbourhood_verified', false)
        ->assertJsonPath('user.neighbourhoodVerified', false);

    $user->refresh();
    expect($user->neighbourhoodName())->toBe('Akwa')
        ->and($user->neighbourhood_verified_at)->toBeNull();

    // Réinitialisation pour tester les rejets lors de l'envoi de coordonnées GPS invalides
    $user->update(['neighbourhood' => null, 'neighbourhood_id' => null, 'neighbourhood_verified_at' => null]);

    // 3. Quartier inexistant
    putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Quartier Imaginaire',
        'latitude' => 4.0256,
        'longitude' => 9.7022,
    ])->assertStatus(422)->assertJsonPath('code', 'NEIGHBOURHOOD_NOT_VERIFIED');

    // 4. Incohérence ville / quartier (Yaoundé + Bonapriso)
    putJson('/api/profile', [
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 4.0256,
        'longitude' => 9.7022,
    ])->assertStatus(422)->assertJsonPath('code', 'NEIGHBOURHOOD_NOT_VERIFIED');

    // 5. Quartier sans coordonnées lat/lng en base
    putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhoodId' => $noCoordsNeighbourhood->id,
        'latitude' => 4.0256,
        'longitude' => 9.7022,
    ])->assertStatus(422)->assertJsonPath('code', 'NEIGHBOURHOOD_NOT_VERIFIED');

    // 6. Position trop éloignée sur PUT /api/profile/location
    putJson('/api/profile/location', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 3.8900,
        'longitude' => 11.5130,
    ])->assertStatus(422)->assertJsonPath('code', 'NEIGHBOURHOOD_NOT_VERIFIED');

    $user->refresh();
    expect($user->neighbourhoodName())->toBeNull()
        ->and($user->neighbourhood_verified_at)->toBeNull();
});

it('validates latitude, longitude and accuracy bounds', function (array $payload, string $errorField) {
    $user = User::factory()->create();
    Passport::actingAs($user);

    putJson('/api/profile', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$errorField]);
})->with([
    'latitude out of range' => [['latitude' => 95, 'longitude' => 9.7], 'latitude'],
    'longitude out of range' => [['latitude' => 4.0, 'longitude' => 190], 'longitude'],
    'negative accuracy' => [['latitude' => 4.0, 'longitude' => 9.7, 'accuracy' => -1], 'accuracy'],
]);

it('preserves existing verified neighbourhood when editing bio without new coordinates, clears it on city change or explicit null', function () {
    $this->seed(GeoSeeder::class);

    $douala = City::where('slug', 'douala')->firstOrFail();
    $bonapriso = Neighbourhood::where('slug', 'bonapriso')->firstOrFail();
    $verifiedAt = now()->subDay()->startOfSecond();

    $user = User::factory()->create([
        'city_id' => $douala->id,
        'city' => 'Douala',
        'neighbourhood_id' => $bonapriso->id,
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => $verifiedAt,
    ]);
    Passport::actingAs($user);

    // 1. Édition de la bio en renvoyant le même quartier déjà vérifié sans GPS -> accepté et horodatage conservé
    putJson('/api/profile', [
        'bio' => 'Bio mise à jour en déplacement',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ])->assertOk()
        ->assertJsonPath('user.bio', 'Bio mise à jour en déplacement')
        ->assertJsonPath('user.neighbourhood', 'Bonapriso')
        ->assertJsonPath('user.neighbourhood_verified', true);

    $user->refresh();
    expect($user->neighbourhood_verified_at->equalTo($verifiedAt))->toBeTrue();

    // 2. Changement de ville sans nouveau quartier -> invalide l'ancien quartier et sa vérification
    putJson('/api/profile', [
        'city' => 'Yaoundé',
    ])->assertOk()
        ->assertJsonPath('user.city', 'Yaoundé')
        ->assertJsonPath('user.neighbourhood', null)
        ->assertJsonPath('user.neighbourhood_verified', false);

    $user->refresh();
    expect($user->neighbourhoodName())->toBeNull()
        ->and($user->neighbourhood_id)->toBeNull()
        ->and($user->neighbourhood_verified_at)->toBeNull();

    // 3. Re-vérification à Yaoundé / Bastos puis effacement explicite du quartier
    putJson('/api/profile', [
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
        'latitude' => 3.8900,
        'longitude' => 11.5130,
        'accuracy' => 10,
    ])->assertOk()->assertJsonPath('user.neighbourhood_verified', true);

    putJson('/api/profile', [
        'neighbourhood' => null,
    ])->assertOk()
        ->assertJsonPath('user.neighbourhood', null)
        ->assertJsonPath('user.neighbourhood_verified', false);
});

it('keeps neighbourhood unverified without GPS and verifies it when GPS coordinates are provided', function () {
    $this->seed(GeoSeeder::class);

    $existingUnverified = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);
    Passport::actingAs($existingUnverified);

    // Sans GPS -> accepté comme non vérifié
    putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ])->assertOk()
        ->assertJsonPath('user.neighbourhood', 'Bonapriso')
        ->assertJsonPath('user.neighbourhood_verified', false)
        ->assertJsonPath('user.needs_neighbourhood_verification', true);

    // Avec GPS valide -> confirmé !
    putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 4.0256,
        'longitude' => 9.7022,
        'accuracy' => 20,
    ])->assertOk()
        ->assertJsonPath('user.neighbourhood_verified', true)
        ->assertJsonPath('user.needs_neighbourhood_verification', false);
});

// =========================================================================
// 3. ONGLET NEIGHBOURHOOD DU FIL SQUARE (PostFeedService & PostPolicy)
// =========================================================================

it('excludes unverified accounts from the Square neighbourhood tab and returns a confirmation prompt', function () {
    $unverifiedViewer = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);

    $verifiedNeighbour = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create();
    $unverifiedNeighbour = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);

    $placedPost = Post::create([
        'user_id' => $verifiedNeighbour->id,
        'body' => 'Publication placée à Bonapriso',
        'place_city' => 'Douala',
        'place_neighbourhood' => 'Bonapriso',
        'audience' => 'public',
    ]);

    $verifiedUnplacedPost = Post::create([
        'user_id' => $verifiedNeighbour->id,
        'body' => 'Publication sans lieu d un voisin vérifié',
        'audience' => 'public',
    ]);

    $unverifiedUnplacedPost = Post::create([
        'user_id' => $unverifiedNeighbour->id,
        'body' => 'Publication sans lieu d un compte non vérifié',
        'audience' => 'public',
    ]);

    // 1. Spectateur non vérifié : fil vide + invitation structurée à confirmer le quartier
    Passport::actingAs($unverifiedViewer);

    $unverifiedResponse = getJson('/api/square/feed?tab=neighbourhood');
    $unverifiedResponse->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('neighbourhood_verification.required', true)
        ->assertJsonPath('neighbourhood_verification.code', 'NEIGHBOURHOOD_CONFIRMATION_REQUIRED')
        ->assertJsonPath('neighbourhood_verification.has_declared_neighbourhood', true)
        ->assertJsonPath('neighbourhood_verification.city', 'Douala')
        ->assertJsonPath('neighbourhood_verification.neighbourhood', 'Bonapriso');

    // 2. Spectateur vérifié : voit les posts du quartier et ceux des voisins vérifiés, mais PAS les posts sans lieu des comptes non vérifiés
    $verifiedViewer = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create();
    Passport::actingAs($verifiedViewer);

    $verifiedResponse = getJson('/api/square/feed?tab=neighbourhood');
    $verifiedResponse->assertOk()
        ->assertJsonPath('neighbourhood_verification.required', false);

    $ids = collect($verifiedResponse->json('data'))->pluck('id')->all();

    expect($ids)->toContain((string) $placedPost->id)
        ->toContain((string) $verifiedUnplacedPost->id)
        ->not->toContain((string) $unverifiedUnplacedPost->id);
});

it('requires verified neighbourhood in the same city to view neighbourhood-audience posts', function () {
    $author = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Message réservé aux voisins de Bonapriso',
        'place_city' => 'Douala',
        'place_neighbourhood' => 'Bonapriso',
        'audience' => PostAudience::Neighbourhood->value,
    ]);

    // Un compte non vérifié dans le même quartier ne peut pas voir le post restreint au quartier
    $unverified = User::factory()->create([
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);
    Passport::actingAs($unverified);
    getJson("/api/square-cr1/posts/{$post->id}")->assertForbidden();

    // Un voisin vérifié dans Bonapriso, Douala peut le voir
    $verifiedNeighbour = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create();
    Passport::actingAs($verifiedNeighbour);
    getJson("/api/square-cr1/posts/{$post->id}")->assertOk();
});

// =========================================================================
// 4. PUBLIC PROFILE RESOURCE : neighbourhood_verified
// =========================================================================

it('exposes neighbourhood_verified boolean on PublicProfileResource and respects showNeighbourhood privacy', function () {
    $verifiedUser = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create([
        'handle' => 'voisin.verifie',
    ]);

    $unverifiedUser = User::factory()->create([
        'handle' => 'voisin.declare',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);

    $hiddenUser = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create([
        'handle' => 'voisin.cache',
        'privacy_settings' => ['showNeighbourhood' => false],
    ]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    getJson("/api/profile/public/{$verifiedUser->handle}")
        ->assertOk()
        ->assertJsonPath('data.city', 'Douala')
        ->assertJsonPath('data.neighbourhood', 'Bonapriso')
        ->assertJsonPath('data.neighbourhood_verified', true)
        ->assertJsonPath('data.neighbourhoodVerified', true);

    getJson("/api/profile/public/{$unverifiedUser->handle}")
        ->assertOk()
        ->assertJsonPath('data.city', 'Douala')
        ->assertJsonPath('data.neighbourhood', 'Bonapriso')
        ->assertJsonPath('data.neighbourhood_verified', false)
        ->assertJsonPath('data.neighbourhoodVerified', false);

    getJson("/api/profile/public/{$hiddenUser->handle}")
        ->assertOk()
        ->assertJsonPath('data.city', 'Douala')
        ->assertJsonPath('data.neighbourhood', null)
        ->assertJsonPath('data.neighbourhood_verified', false)
        ->assertJsonPath('data.neighbourhoodVerified', false);
});

// =========================================================================
// 5. INVITATION DES COMPTES EXISTANTS À CONFIRMER LEUR QUARTIER
// =========================================================================

it('flags existing unverified accounts on GET /api/profile, sends idempotent invitations, and marks them read upon verification', function () {
    $this->seed(GeoSeeder::class);

    $existingUnverified = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);

    $alreadyVerified = User::factory()->withVerifiedNeighbourhood('Douala', 'Bonapriso')->create([
        'status' => 'active',
    ]);

    $withoutNeighbourhood = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => null,
        'neighbourhood_verified_at' => null,
    ]);

    $suspended = User::factory()->create([
        'status' => 'suspended',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'neighbourhood_verified_at' => null,
    ]);

    DeviceToken::create([
        'user_id' => $existingUnverified->id,
        'token' => 'ExponentPushToken[test-token-123]',
        'platform' => 'android',
        'is_active' => true,
    ]);

    Http::fake([
        PushNotificationService::EXPO_PUSH_URL => Http::response([
            'data' => [['status' => 'ok', 'id' => 'ticket-1']],
        ], 200),
    ]);

    // 1. GET /api/profile indique needs_neighbourhood_verification = true
    Passport::actingAs($existingUnverified);
    getJson('/api/profile')
        ->assertOk()
        ->assertJsonPath('data.neighbourhood_verified', false)
        ->assertJsonPath('data.needs_neighbourhood_verification', true);

    // 2. Dry-run ne crée aucune notification
    artisan('profile:invite-neighbourhood-confirmation', ['--dry-run' => true])->assertSuccessful();
    expect($existingUnverified->notifications()->count())->toBe(0);

    // 3. Exécution réelle : invite uniquement le compte actif non vérifié ayant un quartier déclaré (DB + push automatique)
    artisan('profile:invite-neighbourhood-confirmation')->assertSuccessful();

    expect($existingUnverified->notifications()->where('type', ConfirmNeighbourhoodNotification::class)->count())->toBe(1)
        ->and($alreadyVerified->notifications()->count())->toBe(0)
        ->and($withoutNeighbourhood->notifications()->count())->toBe(0)
        ->and($suspended->notifications()->count())->toBe(0);

    Http::assertSent(function ($request) {
        $messages = $request->data();
        $first = $messages[0] ?? [];

        return $request->url() === PushNotificationService::EXPO_PUSH_URL
            && ($first['to'] ?? null) === 'ExponentPushToken[test-token-123]'
            && ($first['data']['type'] ?? null) === ConfirmNeighbourhoodNotification::TYPE
            && ($first['data']['action'] ?? null) === 'confirm_neighbourhood'
            && ($first['data']['deep_link'] ?? null) === 'bango://profile/location';
    });

    // La notification est bien visible dans GET /api/notifications?category=account
    getJson('/api/notifications?category=account')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', ConfirmNeighbourhoodNotification::TYPE)
        ->assertJsonPath('data.0.data.action', 'confirm_neighbourhood');

    // 4. Idempotence : une seconde exécution ne duplique pas l'invitation
    artisan('profile:invite-neighbourhood-confirmation')->assertSuccessful();
    expect($existingUnverified->notifications()->where('type', ConfirmNeighbourhoodNotification::class)->count())->toBe(1);

    // 5. Confirmation du quartier par la position : marque automatiquement l'invitation comme lue
    putJson('/api/profile', [
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'latitude' => 4.0256,
        'longitude' => 9.7022,
        'accuracy' => 12,
    ])->assertOk()
        ->assertJsonPath('user.neighbourhood_verified', true)
        ->assertJsonPath('user.needs_neighbourhood_verification', false);

    expect($existingUnverified->unreadNotifications()->where('type', ConfirmNeighbourhoodNotification::class)->count())->toBe(0);
});
