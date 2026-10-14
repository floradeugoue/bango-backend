<?php

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Square\Hashtag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('public');
});

it('téléverse avec succès une image pour le carrousel Square', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $file = UploadedFile::fake()->image('photo_square.jpg', 1080, 1080)->size(500);

    $response = postJson('/api/square-cr1/media/upload', [
        'file' => $file,
        'kind' => 'photo',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'url',
                'type',
                'mime_type',
                'file_size',
            ],
        ]);
});

it('rejette les types de fichiers non autorisés pour les médias', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $fakePdf = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

    $response = postJson('/api/square-cr1/media/upload', [
        'file' => $fakePdf,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('recherche des personnes par pseudo ou nom', function () {
    $user = User::factory()->create(['status' => 'active']);
    Passport::actingAs($user);

    $alice = User::factory()->create([
        'handle' => 'alice_bango',
        'display_name' => 'Alice Développeuse',
        'status' => 'active',
    ]);

    $bob = User::factory()->create([
        'handle' => 'bob_test',
        'display_name' => 'Bob Designer',
        'status' => 'active',
    ]);

    $response = getJson('/api/square-cr1/search/people?q=alice');

    $response->assertStatus(200);
    $handles = collect($response->json('data'))->pluck('handle')->all();

    expect($handles)->toContain('alice_bango')
        ->not->toContain('bob_test');
});

it('recherche des hashtags par mot-clé et ordonne par posts_count', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    Hashtag::create(['name' => 'BangoMusic', 'normalized_name' => 'bangomusic', 'posts_count' => 15]);
    Hashtag::create(['name' => 'BangoFood', 'normalized_name' => 'bangofood', 'posts_count' => 42]);
    Hashtag::create(['name' => 'TechCameroun', 'normalized_name' => 'techcameroun', 'posts_count' => 3]);

    $response = getJson('/api/square-cr1/search/hashtags/trending?q=bango');

    $response->assertStatus(200);
    $results = $response->json('data');

    expect($results)->toHaveCount(2);
    expect($results[0]['normalized_name'])->toBe('bangofood');
    expect($results[1]['normalized_name'])->toBe('bangomusic');
});

it('recherche des lieux à partir des quartiers et villes réels', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $currency = Currency::firstOrCreate(
        ['code' => 'XAF'],
        ['name' => 'Franc CFA']
    );

    $country = Country::firstOrCreate(
        ['code' => 'CM'],
        [
            'name' => 'Cameroun',
            'iso2' => 'CM',
            'iso3' => 'CMR',
            'currency_id' => $currency->id,
        ]
    );

    $city = City::firstOrCreate(
        ['slug' => 'douala'],
        ['name' => 'Douala', 'country_id' => $country->id]
    );

    Neighbourhood::firstOrCreate(
        ['slug' => 'bonanjo'],
        ['name' => 'Bonanjo', 'city_id' => $city->id]
    );

    $response = getJson('/api/square-cr1/search/places?q=Bonanjo');

    $response->assertStatus(200)
        ->assertJsonPath('data.0.label', 'Bonanjo')
        ->assertJsonPath('data.0.city', 'Douala');
});
