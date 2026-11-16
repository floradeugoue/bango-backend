<?php

use App\Enums\Delegation\DelegationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Création des devises et pays de référence
    $this->xaf = Currency::create([
        'code' => 'XAF',
        'name' => 'Franc CFA BEAC',
        'symbol' => 'FCFA',
        'rate_from_xaf' => 1.0,
        'round_to' => 0,
        'is_active' => true,
    ]);

    $this->xof = Currency::create([
        'code' => 'XOF',
        'name' => 'Franc CFA BCEAO',
        'symbol' => 'FCFA',
        'rate_from_xaf' => 1.0,
        'round_to' => 0,
        'is_active' => true,
    ]);

    $this->countryCameroon = Country::create([
        'code' => 'CM',
        'iso2' => 'CM',
        'iso3' => 'CMR',
        'name' => 'Cameroun',
        'phone_code' => '+237',
        'currency_id' => $this->xaf->id,
        'is_active' => true,
    ]);

    $this->countryIvoryCoast = Country::create([
        'code' => 'CI',
        'iso2' => 'CI',
        'iso3' => 'CIV',
        'name' => "Côte d'Ivoire",
        'phone_code' => '+225',
        'currency_id' => $this->xof->id,
        'is_active' => true,
    ]);

    $this->cityDouala = City::create([
        'country_id' => $this->countryCameroon->id,
        'name' => 'Douala',
        'slug' => 'douala',
        'is_active' => true,
    ]);

    $this->cityAbidjan = City::create([
        'country_id' => $this->countryIvoryCoast->id,
        'name' => 'Abidjan',
        'slug' => 'abidjan',
        'is_active' => true,
    ]);

    $this->landlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'country_code' => 'CM',
        'country_id' => $this->countryCameroon->id,
    ]);
});

test('une annonce refuse formellement la devise "FCFA" et exige un code ISO 4217 de 3 lettres', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Bel appartement à Bonapriso',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 250000,
        'currency' => 'FCFA',
        'bedrooms' => 2,
        'showers' => 2,
        'area' => 85,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['currency']);
});

test('un brouillon refuse également la devise "FCFA"', function () {
    Passport::actingAs($this->landlord);

    $draftPayload = [
        'submission_key' => 'draft-currency-fcfa',
        'title' => 'Brouillon test devise',
        'price' => 150000,
        'currency' => 'FCFA',
    ];

    $response = $this->postJson('/api/listings/form/draft', $draftPayload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['currency']);
});

test('une annonce refuse une devise inconnue ou d\'un pays non ouvert (ex: XYZ)', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Bel appartement à Bonapriso',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 250000,
        'currency' => 'XYZ',
        'bedrooms' => 2,
        'showers' => 2,
        'area' => 85,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['currency']);
});

test('une annonce accepte un code ISO 4217 valide et normalise les minuscules', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Bel appartement à Bonapriso',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 250000,
        'currency' => 'xaf',
        'bedrooms' => 2,
        'showers' => 2,
        'area' => 85,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.currency', 'XAF');

    $this->assertDatabaseHas('listings', [
        'owner_id' => $this->landlord->id,
        'currency' => 'XAF',
    ]);
});

test('lorsque la devise est omise elle est automatiquement déduite du pays du compte utilisateur (ex: CI -> XOF)', function () {
    $ivoryUser = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'country_code' => 'CI',
        'country_id' => $this->countryIvoryCoast->id,
    ]);

    Passport::actingAs($ivoryUser);

    $payload = [
        'title' => 'Villa à Cocody Angré',
        'mode' => 'rent',
        'kind' => 'villa',
        'price' => 450000,
        // Pas de champ 'currency'
        'bedrooms' => 3,
        'showers' => 3,
        'area' => 180,
        'city' => 'Abidjan',
        'neighbourhood' => 'Cocody',
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.currency', 'XOF');

    $this->assertDatabaseHas('listings', [
        'owner_id' => $ivoryUser->id,
        'currency' => 'XOF',
    ]);
});

test('lorsque la devise est omise elle est automatiquement déduite de la ville si l\'utilisateur n\'a pas de pays', function () {
    $neutralUser = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
        'country_code' => null,
        'country_id' => null,
    ]);

    Passport::actingAs($neutralUser);

    $payload = [
        'title' => 'Appartement à Abidjan',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 200000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 70,
        'city' => 'Abidjan',
        'neighbourhood' => 'Marcory',
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.currency', 'XOF');
});

test('la modification d\'une annonce rejette "FCFA" et permet au propriétaire de changer de devise valide', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'currency' => 'XAF',
        'price' => 300000,
    ]);

    // Tentative avec FCFA
    $badResponse = $this->patchJson("/api/listings/{$listing->id}", [
        'currency' => 'FCFA',
    ]);
    $badResponse->assertStatus(422)->assertJsonValidationErrors(['currency']);

    // Mise à jour valide avec XOF
    $okResponse = $this->patchJson("/api/listings/{$listing->id}", [
        'currency' => 'XOF',
    ]);
    $okResponse->assertStatus(200);

    expect($listing->fresh()->currency)->toBe('XOF');
});

test('un agent mandaté ne peut pas modifier la devise d\'une annonce (PRICE_OWNER_ONLY)', function () {
    $agent = User::factory()->create([
        'badge' => 'agent',
        'is_verified' => true,
    ]);

    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'currency' => 'XAF',
    ]);

    Delegation::create([
        'bailleur_id' => $this->landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => ['edit'],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(5),
    ]);

    Passport::actingAs($agent);

    $response = $this->patchJson("/api/listings/{$listing->id}", [
        'currency' => 'XOF',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('error', 'PRICE_OWNER_ONLY');
});
