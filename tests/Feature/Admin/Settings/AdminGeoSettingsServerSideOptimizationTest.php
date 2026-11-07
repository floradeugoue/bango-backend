<?php

namespace Tests\Feature\Admin\Settings;

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingStatus;
use App\Models\Admin\AdminSession;
use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Listing\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withCredentials();
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $this->operator = User::factory()->create([
        'email' => 'admin.geo.'.uniqid().'@bango.app',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    $this->sessionId = bin2hex(random_bytes(32));
    $this->csrfToken = bin2hex(random_bytes(32));

    AdminSession::create([
        'id' => $this->sessionId,
        'user_id' => $this->operator->id,
        'csrf_token' => $this->csrfToken,
        'ip_address' => '127.0.0.1',
        'two_factor_verified_at' => now(),
        'last_activity_at' => now(),
        'expires_at' => now()->addHours(12),
    ]);
});

test('admin settings cities supports server-side pagination, search, country filtering, and aggregated counts', function () {
    $curr = Currency::firstOrCreate(['code' => 'XAF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA']);

    $countryCm = Country::create([
        'name' => 'Cameroun',
        'code' => 'CM',
        'iso2' => 'CM',
        'iso3' => 'CMR',
        'phone_code' => '237',
        'currency_id' => $curr->id,
    ]);

    $countryCi = Country::create([
        'name' => 'Côte d\'Ivoire',
        'code' => 'CI',
        'iso2' => 'CI',
        'iso3' => 'CIV',
        'phone_code' => '225',
        'currency_id' => $curr->id,
    ]);

    $cityDouala = City::create([
        'country_id' => $countryCm->id,
        'name' => 'Douala',
        'slug' => 'douala',
        'is_active' => true,
    ]);

    $cityAbidjan = City::create([
        'country_id' => $countryCi->id,
        'name' => 'Abidjan',
        'slug' => 'abidjan',
        'is_active' => true,
    ]);

    $areaAkwa = Neighbourhood::create([
        'city_id' => $cityDouala->id,
        'name' => 'Akwa',
        'slug' => 'douala-akwa',
        'is_active' => true,
        'is_ignored' => false,
    ]);

    $owner = User::factory()->create([
        'city_id' => $cityDouala->id,
        'city' => 'Douala',
    ]);

    Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Bureau Akwa Centre',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'kind' => ListingKind::Office,
        'status' => ListingStatus::Published,
    ]);

    // 1. Search by name
    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/settings/cities?q=Douala');

    $res->assertOk();
    $data = $res->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Douala')
        ->and($data[0]['listings_count'])->toBe(1)
        ->and($data[0]['users_count'])->toBe(1)
        ->and($data[0]['neighbourhoods_count'])->toBe(1);

    // 2. Filter by country
    $resCi = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/settings/cities?country=CI');

    $resCi->assertOk();
    $dataCi = $resCi->json('data');
    expect($dataCi)->toHaveCount(1)
        ->and($dataCi[0]['name'])->toBe('Abidjan');
});

test('admin settings areas supports server-side pagination, search, city filtering, and listings count', function () {
    $curr = Currency::firstOrCreate(['code' => 'XOF'], ['name' => 'Franc CFA', 'symbol' => 'FCFA']);

    $country = Country::create([
        'name' => 'Sénégal',
        'code' => 'SN',
        'iso2' => 'SN',
        'iso3' => 'SEN',
        'phone_code' => '221',
        'currency_id' => $curr->id,
    ]);

    $dakar = City::create([
        'country_id' => $country->id,
        'name' => 'Dakar',
        'slug' => 'dakar',
        'is_active' => true,
    ]);

    $almadies = Neighbourhood::create([
        'city_id' => $dakar->id,
        'name' => 'Almadies',
        'slug' => 'dakar-almadies',
        'is_active' => true,
        'is_ignored' => false,
    ]);

    $plateau = Neighbourhood::create([
        'city_id' => $dakar->id,
        'name' => 'Plateau',
        'slug' => 'dakar-plateau',
        'is_active' => true,
        'is_ignored' => true,
    ]);

    $owner = User::factory()->create();
    Listing::factory()->create([
        'owner_id' => $owner->id,
        'created_by_id' => $owner->id,
        'title' => 'Villa bord de mer',
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'kind' => ListingKind::Villa,
        'status' => ListingStatus::Published,
    ]);

    // 1. Search by name and city
    $res = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/settings/areas?city=dakar&q=Almadies');

    $res->assertOk();
    $data = $res->json('data');
    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Almadies')
        ->and($data[0]['listings_count'])->toBe(1);

    // 2. Filter by status ignored
    $resIgnored = $this->withUnencryptedCookie('bango_admin_session', $this->sessionId)
        ->getJson('/api/admin/settings/areas?city=dakar&status=ignored');

    $resIgnored->assertOk();
    $dataIgnored = $resIgnored->json('data');
    expect($dataIgnored)->toHaveCount(1)
        ->and($dataIgnored[0]['name'])->toBe('Plateau');
});
