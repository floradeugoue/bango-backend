<?php

use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use App\Services\Geo\ReverseGeocodingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(RoleSeeder::class);

    $this->currency = Currency::firstOrCreate(
        ['code' => 'XAF'],
        ['name' => 'Franc CFA', 'symbol' => 'FCFA']
    );

    $this->country = Country::create([
        'name' => 'Cameroun',
        'code' => 'CM',
        'iso2' => 'CM',
        'iso3' => 'CMR',
        'phone_code' => '237',
        'currency_id' => $this->currency->id,
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'name' => 'Marie',
        'terms_accepted_at' => now(),
    ]);

    $adminRole = Role::where('slug', 'admin')->firstOrFail();
    $this->admin = User::factory()->create([
        'name' => 'Admin Geo',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);
});

test('geo: recherche de ville par q trie les résultats par population décroissante et limite à 20', function (): void {
    // Création de plusieurs villes commençant par "Dou" avec différentes populations
    City::create([
        'country_id' => $this->country->id,
        'name' => 'Douala',
        'slug' => 'douala-cm',
        'lat' => 4.0511,
        'lng' => 9.7679,
        'population' => 3000000,
        'is_active' => true,
    ]);

    City::create([
        'country_id' => $this->country->id,
        'name' => 'Doumé',
        'slug' => 'doume-cm',
        'lat' => 4.2400,
        'lng' => 13.4400,
        'population' => 20000,
        'is_active' => true,
    ]);

    City::create([
        'country_id' => $this->country->id,
        'name' => 'Doulougou',
        'slug' => 'doulougou-cm',
        'lat' => 11.9000,
        'lng' => -1.3000,
        'population' => 5000,
        'is_active' => true,
    ]);

    // 25 petites villes de test pour vérifier la limite de 20
    for ($i = 1; $i <= 25; $i++) {
        City::create([
            'country_id' => $this->country->id,
            'name' => "Doutest {$i}",
            'slug' => "doutest-{$i}-cm",
            'population' => 100 + $i,
            'is_active' => true,
        ]);
    }

    $response = $this->getJson("/api/geo/cities?country_id={$this->country->id}&q=dou");

    $response->assertOk();
    $data = $response->json();

    expect($data)->toBeArray();
    expect(count($data))->toBeLessThanOrEqual(20);
    // Douala (pop 3M) doit être impérativement en première position
    expect($data[0]['name'])->toBe('Douala');
    expect($data[1]['name'])->toBe('Doumé');
});

test('geo: quartiers OpenStreetMap chargés à la demande quand une ville sans quartiers est interrogée', function (): void {
    // Ville sans quartiers en base
    $city = City::create([
        'country_id' => $this->country->id,
        'name' => 'Bafoussam',
        'slug' => 'bafoussam-cm',
        'lat' => 5.4781,
        'lng' => 10.4179,
        'population' => 400000,
        'is_active' => true,
        'osm_fetched_at' => null,
    ]);

    // Fausse réponse de l'Overpass API d'OpenStreetMap
    Http::fake([
        '*overpass*' => Http::response([
            'elements' => [
                [
                    'type' => 'node',
                    'id' => 123456,
                    'lat' => 5.4800,
                    'lon' => 10.4200,
                    'tags' => [
                        'place' => 'suburb',
                        'name' => 'Djeleng',
                    ],
                ],
                [
                    'type' => 'node',
                    'id' => 123457,
                    'lat' => 5.4700,
                    'lon' => 10.4100,
                    'tags' => [
                        'place' => 'neighbourhood',
                        'name' => 'Kamkop',
                    ],
                ],
            ],
        ], 200),
    ]);

    $response = $this->getJson("/api/geo/neighbourhoods?city_id={$city->id}");

    $response->assertOk();
    $response->assertHeader('X-Attribution', '© contributeurs OpenStreetMap');
    $data = $response->json();

    expect(count($data))->toBe(2);
    $names = array_column($data, 'name');
    expect($names)->toContain('Djeleng');
    expect($names)->toContain('Kamkop');

    // Vérification que les quartiers ont été persistés en base locale avec la source OSM
    $city->refresh();
    expect($city->osm_fetched_at)->not->toBeNull();
    expect(Neighbourhood::where('city_id', $city->id)->where('source', 'osm')->count())->toBe(2);
});

test('geo: un quartier soumis par un utilisateur est mis en attente et non visible publiquement avant validation', function (): void {
    $city = City::create([
        'country_id' => $this->country->id,
        'name' => 'Kribi',
        'slug' => 'kribi-cm',
        'is_active' => true,
        'osm_fetched_at' => now(),
    ]);

    // Quartier existant vérifié
    Neighbourhood::create([
        'city_id' => $city->id,
        'name' => 'Grand Batanga',
        'slug' => 'grand-batanga',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Passport::actingAs($this->user);

    // L'utilisateur soumet « Autre quartier »
    $submitRes = $this->postJson('/api/geo/neighbourhoods', [
        'city_id' => $city->id,
        'name' => 'Nouveau Quartier Plage',
        'source' => 'user',
    ]);

    $submitRes->assertStatus(201);
    $submitRes->assertJsonPath('status', 'pending');
    $submitRes->assertJsonPath('is_active', false);

    $pendingId = $submitRes->json('id');
    $pendingNeighbourhood = Neighbourhood::find($pendingId);
    expect($pendingNeighbourhood->isPending())->toBeTrue();
    expect($pendingNeighbourhood->submitted_by_user_id)->toBe($this->user->id);

    // La liste publique ne doit PAS contenir le quartier en attente
    $publicRes = $this->getJson("/api/geo/neighbourhoods?city_id={$city->id}");
    $publicRes->assertOk();
    $publicNames = array_column($publicRes->json(), 'name');
    expect($publicNames)->toContain('Grand Batanga');
    expect($publicNames)->not->toContain('Nouveau Quartier Plage');

    // Back-office admin : liste des quartiers en attente
    Passport::actingAs($this->admin);
    $pendingRes = $this->getJson('/admin/geo/neighbourhoods/pending');
    $pendingRes->assertOk();
    $pendingItems = $pendingRes->json('data');
    expect(array_column($pendingItems, 'name'))->toContain('Nouveau Quartier Plage');

    // Back-office admin : approbation du quartier
    $approveRes = $this->postJson("/admin/geo/neighbourhoods/{$pendingId}/approve");
    $approveRes->assertOk();
    $approveRes->assertJsonPath('success', true);

    // Une fois validé, il apparaît dans la liste publique
    $publicResAfter = $this->getJson("/api/geo/neighbourhoods?city_id={$city->id}");
    $publicResAfter->assertOk();
    $publicNamesAfter = array_column($publicResAfter->json(), 'name');
    expect($publicNamesAfter)->toContain('Nouveau Quartier Plage');
});

test('geo: géocodage inverse avec Nominatim ou Photon résout le quartier et met en cache', function (): void {
    Http::fake([
        '*nominatim*' => Http::response([
            'address' => [
                'suburb' => 'Akwa',
                'city' => 'Douala',
                'country_code' => 'cm',
            ],
        ], 200),
    ]);

    $service = app(ReverseGeocodingService::class);
    $result = $service->reverseGeocode(4.0511, 9.7679);

    expect($result['neighbourhood'])->toBe('Akwa');
    expect($result['city'])->toBe('Douala');
    expect($result['country_code'])->toBe('CM');
    expect($result['attribution'])->toContain('OpenStreetMap');
});
