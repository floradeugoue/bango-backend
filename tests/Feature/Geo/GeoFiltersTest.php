<?php

use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use Database\Seeders\GeoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(GeoSeeder::class);
});

test('public geo countries endpoint returns active countries with currency', function () {
    $response = $this->getJson('/api/geo/countries?is_active=1');

    $response->assertOk();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(count($data))->toBeGreaterThanOrEqual(5);

    $cm = collect($data)->firstWhere('code', 'CM');
    expect($cm)->not->toBeNull();
    expect($cm['iso2'])->toBe('CM');
    expect($cm['currency'])->not->toBeNull();
    expect($cm['currency']['code'])->toBe('XAF');
});

test('public geo cities endpoint filters by country_id and returns flat array with coordinates', function () {
    $country = Country::where('code', 'CM')->firstOrFail();

    $response = $this->getJson("/api/geo/cities?country_id={$country->id}");

    $response->assertOk();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(count($data))->toBeGreaterThanOrEqual(2);

    $douala = collect($data)->firstWhere('slug', 'douala');
    expect($douala)->not->toBeNull();
    expect($douala['name'])->toBe('Douala');
    expect($douala['lat'])->toBe(4.0511);
    expect($douala['lng'])->toBe(9.7679);
});

test('public geo neighbourhoods endpoint filters by city_id and returns flat array with coordinates', function () {
    $city = City::where('slug', 'douala')->firstOrFail();

    $response = $this->getJson("/api/geo/neighbourhoods?city_id={$city->id}");

    $response->assertOk();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(count($data))->toBeGreaterThanOrEqual(1);

    $bonapriso = collect($data)->firstWhere('name', 'Bonapriso');
    expect($bonapriso)->not->toBeNull();
    expect($bonapriso['lat'])->toBe(4.0256);
    expect($bonapriso['lng'])->toBe(9.7022);
});

test('public geo currencies and operators endpoints respond properly with prefixes', function () {
    $currencies = $this->getJson('/api/geo/currencies');
    $currencies->assertOk();
    expect($currencies->json())->toBeArray();

    $operators = $this->getJson('/api/geo/operators');
    $operators->assertOk();
    $data = $operators->json();
    expect($data)->toBeArray();

    $mtnCm = collect($data)->firstWhere('code', 'mtn_cm');
    expect($mtnCm)->not->toBeNull();
    expect($mtnCm['prefixes'])->toBeArray();
    expect($mtnCm['prefixes'])->toContain('65', '67', '68');
});

test('geo genders and root genders endpoints return standard gender definitions with labels', function () {
    $geoGenders = $this->getJson('/api/geo/genders');
    $geoGenders->assertOk();
    $data1 = $geoGenders->json();
    expect($data1)->toBeArray();
    expect(count($data1))->toBe(4);

    $values = collect($data1)->pluck('value')->all();
    expect($values)->toEqual(['woman', 'man', 'other', 'undisclosed']);

    $rootGenders = $this->getJson('/api/genders');
    $rootGenders->assertOk();
    $data2 = $rootGenders->json();
    expect($data2)->toEqual($data1);

    $webGenders = $this->getJson('/genders');
    $webGenders->assertOk();
    expect($webGenders->json())->toEqual($data1);
});

test('admin geo cities and neighbourhoods store endpoints are idempotent and handle unique slugs', function () {
    $role = Role::where('slug', 'direction')->firstOrFail();
    $user = User::factory()->create([
        'email' => 'admin.geo@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $sessionId = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $sessionId,
        'user_id' => $user->id,
        'csrf_token' => $csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);

    $country = Country::where('code', 'CM')->firstOrFail();

    // 1. Create a new city via admin route with coordinates
    $cityRes1 = $this->withHeader('X-Bango-Admin-Session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/geo/cities', [
            'country_id' => $country->id,
            'name' => 'Kribi',
            'slug' => 'cm-kribi',
            'lat' => 2.9377,
            'lng' => 9.9077,
            'is_active' => true,
        ]);

    $cityRes1->assertStatus(201);
    $cityId = $cityRes1->json('id');
    expect($cityId)->not->toBeNull();
    expect($cityRes1->json('lat'))->toBe(2.9377);
    expect($cityRes1->json('lng'))->toBe(9.9077);

    // 2. Replay creating the same city (idempotent, returns 200)
    $cityRes2 = $this->withHeader('X-Bango-Admin-Session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/geo/cities', [
            'country_id' => $country->id,
            'name' => 'Kribi',
            'slug' => 'cm-kribi',
            'lat' => 2.9377,
            'lng' => 9.9077,
            'is_active' => true,
        ]);

    $cityRes2->assertStatus(200);
    expect($cityRes2->json('id'))->toBe($cityId);
    expect($cityRes2->json('lat'))->toBe(2.9377);
    expect($cityRes2->json('lng'))->toBe(9.9077);

    // 3. Create a neighbourhood via admin route with coordinates
    $neighRes1 = $this->withHeader('X-Bango-Admin-Session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/geo/neighbourhoods', [
            'city_id' => $cityId,
            'name' => 'Mboa Manga',
            'slug' => 'cm-kribi-mboa-manga',
            'lat' => 2.9410,
            'lng' => 9.9120,
            'is_active' => true,
        ]);

    $neighRes1->assertStatus(201);
    $neighId = $neighRes1->json('id');
    expect($neighId)->not->toBeNull();
    expect($neighRes1->json('lat'))->toBe(2.9410);
    expect($neighRes1->json('lng'))->toBe(9.9120);

    // 4. Replay creating the same neighbourhood (idempotent, returns 200)
    $neighRes2 = $this->withHeader('X-Bango-Admin-Session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/geo/neighbourhoods', [
            'city_id' => $cityId,
            'name' => 'Mboa Manga',
            'slug' => 'cm-kribi-mboa-manga',
            'lat' => 2.9410,
            'lng' => 9.9120,
            'is_active' => true,
        ]);

    $neighRes2->assertStatus(200);
    expect($neighRes2->json('id'))->toBe($neighId);
    expect($neighRes2->json('lat'))->toBe(2.9410);
    expect($neighRes2->json('lng'))->toBe(9.9120);

    // 5. Create / update operator via admin route with prefixes
    $opRes = $this->withHeader('X-Bango-Admin-Session', $sessionId)
        ->withHeader('X-CSRF-Token', $csrfToken)
        ->postJson('/api/admin/geo/operators', [
            'country_id' => $country->id,
            'name' => 'Nexttel Cameroun',
            'code' => 'nexttel_cm',
            'prefixes' => ['66'],
            'is_active' => true,
        ]);

    $opRes->assertStatus(201);
    expect($opRes->json('prefixes'))->toBe(['66']);
});

test('public geo countries endpoint excludes inactive countries like France when filtered by is_active', function () {
    $activeCountries = $this->getJson('/api/geo/countries?is_active=1')->json();
    $activeCodes = collect($activeCountries)->pluck('code')->all();

    // The 8 residence countries from AU-08 + Kenya from PAY-03
    expect($activeCodes)->toContain('SN', 'CM', 'CI', 'GA', 'BJ', 'TG', 'GH', 'NG', 'KE');
    expect($activeCodes)->not->toContain('FR');

    // France exists in database but is inactive
    $allCountries = $this->getJson('/api/geo/countries')->json();
    $france = collect($allCountries)->firstWhere('code', 'FR');
    expect($france)->not->toBeNull();
    expect((bool) $france['is_active'])->toBeFalse();
});

test('operator logos resolve to valid absolute URLs and new operators for Gabon, Senegal, Benin, Togo, Kenya exist', function () {
    $operators = $this->getJson('/api/geo/operators')->json();

    // Check Gabon operators
    $airtelGa = collect($operators)->firstWhere('code', 'airtel_ga');
    expect($airtelGa)->not->toBeNull();
    expect($airtelGa['prefixes'])->toContain('074', '077');
    expect($airtelGa['logo_url'])->toContain('/images/operators/airtel.svg');

    $moovGa = collect($operators)->firstWhere('code', 'moov_ga');
    expect($moovGa)->not->toBeNull();
    expect($moovGa['prefixes'])->toContain('062', '065', '066');

    // Check Senegal operators (Orange, Wave, Free Money)
    $waveSn = collect($operators)->firstWhere('code', 'wave_sn');
    expect($waveSn)->not->toBeNull();
    expect($waveSn['prefixes'])->toContain('77', '76');

    $freeSn = collect($operators)->firstWhere('code', 'free_sn');
    expect($freeSn)->not->toBeNull();
    expect($freeSn['prefixes'])->toContain('76');

    // Check Benin operators
    $mtnBj = collect($operators)->firstWhere('code', 'mtn_bj');
    expect($mtnBj)->not->toBeNull();
    expect($mtnBj['prefixes'])->toContain('51', '97');

    // Check Togo operators
    $tmoneyTg = collect($operators)->firstWhere('code', 'tmoney_tg');
    expect($tmoneyTg)->not->toBeNull();
    expect($tmoneyTg['prefixes'])->toContain('90', '70');

    // Check Kenya operator (M-Pesa)
    $mpesaKe = collect($operators)->firstWhere('code', 'mpesa_ke');
    expect($mpesaKe)->not->toBeNull();
    expect($mpesaKe['prefixes'])->toContain('070', '011');
    expect($mpesaKe['logo_url'])->toContain('/images/operators/mpesa.svg');

    // Ensure no example.com URLs remain in seeded operators
    foreach ($operators as $op) {
        expect($op['logo_url'])->not->toContain('example.com');
    }
});

test('test neighbourhoods in Douala are deactivated or filtered out', function () {
    $douala = City::where('slug', 'douala')->firstOrFail();

    // Create test neighbourhoods like the ones created on dev/test servers
    Neighbourhood::create([
        'city_id' => $douala->id,
        'name' => 'Quartier Test BO',
        'slug' => 'cm-douala-quartier-test-bo',
        'is_active' => false,
    ]);
    Neighbourhood::create([
        'city_id' => $douala->id,
        'name' => 'Test 41608',
        'slug' => 'cm-douala-test-41608',
        'is_active' => false,
    ]);

    $activeNeighbourhoods = $this->getJson("/api/geo/neighbourhoods?city_id={$douala->id}&is_active=1")->json();
    $activeNames = collect($activeNeighbourhoods)->pluck('name')->all();

    expect($activeNames)->not->toContain('Quartier Test BO');
    expect($activeNames)->not->toContain('Test 41608');
});
