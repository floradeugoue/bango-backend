<?php

use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    \Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);
    $role = \App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->user = \App\Models\User::factory()->create(['role_id' => $role->id]);
    \Laravel\Passport\Passport::actingAs($this->user, ['*']);
    $this->currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
});

it('can list countries', function () {
    Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $this->currency->id]);
    
    $response = $this->getJson('/api/geo/countries');
    
    $response->assertStatus(200)
             ->assertJsonCount(1)
             ->assertJsonPath('0.name', 'Cameroun');
});

it('can create a country', function () {
    $response = $this->postJson('/api/geo/countries', [
        'name' => 'Senegal',
        'code' => 'SN',
        'iso2' => 'SN',
        'iso3' => 'SEN',
        'currency_id' => $this->currency->id,
        'is_active' => true
    ]);

    $response->assertStatus(201)->assertJsonPath('name', 'Senegal');
    $this->assertDatabaseHas('countries', ['name' => 'Senegal']);
});

it('validates country creation', function () {
    $response = $this->postJson('/api/geo/countries', []);
    $response->assertStatus(422)->assertJsonValidationErrors(['name', 'code', 'iso2', 'iso3', 'currency_id']);
});
