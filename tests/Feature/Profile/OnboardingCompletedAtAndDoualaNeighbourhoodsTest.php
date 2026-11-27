<?php

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use Database\Seeders\NeighbourhoodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

// =========================================================================
// 1. DATE DE FIN D'INSCRIPTION DANS LE PROFIL (onboardingCompletedAt)
// =========================================================================

it('returns onboardingCompletedAt as ISO 8601 when birthDate and termsAcceptedAt are present, null otherwise', function () {
    // 1. Utilisateur sans date de naissance ni CGU
    $userIncomplete = User::factory()->create([
        'birth_date' => null,
        'terms_accepted_at' => null,
        'onboarding_completed_at' => null,
    ]);
    Passport::actingAs($userIncomplete);

    $res1 = getJson('/api/profile');
    $res1->assertOk()
        ->assertJsonPath('data.birthDate', null)
        ->assertJsonPath('data.termsAcceptedAt', null)
        ->assertJsonPath('data.onboardingCompletedAt', null);

    // 2. Utilisateur avec seulement birthDate
    $userOnlyBirth = User::factory()->create([
        'birth_date' => '1996-03-14',
        'terms_accepted_at' => null,
        'onboarding_completed_at' => null,
    ]);
    Passport::actingAs($userOnlyBirth);

    $res2 = getJson('/api/profile');
    $res2->assertOk()
        ->assertJsonPath('data.birthDate', '1996-03-14')
        ->assertJsonPath('data.termsAcceptedAt', null)
        ->assertJsonPath('data.onboardingCompletedAt', null);

    // 3. Utilisateur avec les deux : onboardingCompletedAt est renseigné en ISO 8601
    $termsDate = Carbon::parse('2026-11-26T10:02:11+01:00');
    $completedDate = Carbon::parse('2026-11-26T10:02:40+01:00');

    $userComplete = User::factory()->create([
        'birth_date' => '1996-03-14',
        'terms_accepted_at' => $termsDate,
        'onboarding_completed_at' => $completedDate,
    ]);
    Passport::actingAs($userComplete);

    $res3 = getJson('/api/profile');
    $res3->assertOk()
        ->assertJsonPath('data.id', $userComplete->id)
        ->assertJsonPath('data.birthDate', '1996-03-14')
        ->assertJsonPath('data.termsAcceptedAt', $userComplete->fresh()->terms_accepted_at->toIso8601String())
        ->assertJsonPath('data.onboardingCompletedAt', $userComplete->fresh()->onboarding_completed_at->toIso8601String());
});

it('automatically sets onboarding_completed_at upon saving user when both birth_date and terms_accepted_at exist', function () {
    $user = User::factory()->create([
        'birth_date' => null,
        'terms_accepted_at' => null,
    ]);
    expect($user->onboarding_completed_at)->toBeNull();

    // Renseigner la date de naissance
    $user->update(['birth_date' => '1998-05-20']);
    expect($user->fresh()->onboarding_completed_at)->toBeNull();

    // Accepter les CGU
    $now = now();
    $user->update(['terms_accepted_at' => $now]);
    expect($user->fresh()->onboarding_completed_at)->not->toBeNull();
});

it('backfills onboarding_completed_at for existing users via artisan command', function () {
    $userWithout = User::factory()->create([
        'birth_date' => '1995-10-10',
        'terms_accepted_at' => Carbon::parse('2026-11-20T08:00:00+01:00'),
    ]);
    DB::table('users')->where('id', $userWithout->id)->update(['onboarding_completed_at' => null]);

    // Test Dry-run
    artisan('users:backfill-onboarding', ['--dry-run' => true])
        ->assertSuccessful();
    expect($userWithout->fresh()->onboarding_completed_at)->toBeNull();

    // Exécution réelle
    artisan('users:backfill-onboarding')
        ->assertSuccessful();

    $userWithout->refresh();
    expect($userWithout->onboarding_completed_at)->not->toBeNull()
        ->and($userWithout->onboarding_completed_at->toIso8601String())->toContain('2026-11-20');
});

// =========================================================================
// 2. LISTE COMPLETE DES QUARTIERS DE DOUALA ET PUT /profile/location SANS GPS
// =========================================================================

it('seeds the complete list of Douala neighbourhoods and serves them via GET /api/neighbourhoods', function () {
    $currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $country = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id, 'is_active' => true]);
    $douala = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $country->id, 'lat' => 4.0511, 'lng' => 9.7679, 'is_active' => true]);

    $seeder = new NeighbourhoodSeeder;
    $seeder->run();

    // Vérifier les quartiers indispensables
    $mustHave = [
        'Akwa', 'Akwa Nord', 'Bali', 'Bangue', 'Beedi', 'Bépanda', 'Bonabéri', 'Bonamoussadi',
        'Bonanjo', 'Bonapriso', 'Cité des Palmiers', 'Dakar', 'Deïdo', 'Japoma', 'Kotto',
        'Logbaba', 'Logbessou', 'Logpom', 'Mabanda', 'Makepe', 'Mboppi', 'Ndog-Passi',
        'Ndogbong', 'Ndokoti', 'New-Bell', 'Nylon', 'PK8', 'PK10', 'PK14', 'PK21', 'Sodiko', 'Village', 'Yassa',
        'Aéroport', 'Denver', 'Camp Yabassi', 'Grand Hangar', 'Santa Barbara', 'Youpwe',
    ];

    foreach ($mustHave as $name) {
        expect(Neighbourhood::where('city_id', $douala->id)->where('name', $name)->exists())
            ->toBeTrue("Le quartier {$name} doit être présent dans la base de données pour Douala.");
    }

    // Requête HTTP GET /api/neighbourhoods?city_id=...
    $response = getJson("/api/neighbourhoods?city_id={$douala->id}");
    $response->assertOk();
    $data = $response->json();

    expect(count($data))->toBeGreaterThanOrEqual(180);
});

it('allows PUT /api/profile/location without latitude and longitude to register unverified neighbourhood', function () {
    $currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $country = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id, 'is_active' => true]);
    $douala = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $country->id, 'lat' => 4.0511, 'lng' => 9.7679, 'is_active' => true]);
    $kotto = Neighbourhood::create(['name' => 'Kotto', 'slug' => 'kotto', 'city_id' => $douala->id, 'lat' => 4.0950, 'lng' => 9.7560, 'is_active' => true]);

    $user = User::factory()->create([
        'city_id' => null,
        'neighbourhood_id' => null,
        'neighbourhood_verified_at' => null,
    ]);
    Passport::actingAs($user);

    $response = putJson('/api/profile/location', [
        'cityId' => $douala->id,
        'neighbourhoodId' => $kotto->id,
        // Pas de latitude ni longitude
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('user.city', 'Douala')
        ->assertJsonPath('user.neighbourhood', 'Kotto')
        ->assertJsonPath('user.neighbourhood_verified', false)
        ->assertJsonPath('user.neighbourhoodVerified', false)
        ->assertJsonPath('user.needs_neighbourhood_verification', true);

    $user->refresh();
    expect($user->city_id)->toBe($douala->id)
        ->and($user->neighbourhood_id)->toBe($kotto->id)
        ->and($user->neighbourhoodName())->toBe('Kotto')
        ->and($user->neighbourhood_verified_at)->toBeNull();
});
