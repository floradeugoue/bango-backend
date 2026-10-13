<?php

use App\Models\Auth\Role;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->user = User::factory()->create(['role_id' => $role->id]);
    Passport::actingAs($this->user, ['*']);

    $currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $country = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]);
    $this->city1 = City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $country->id]);
    $this->city2 = City::create(['name' => 'Yaoundé', 'slug' => 'yaounde', 'country_id' => $country->id]);
});

it('can list and filter neighbourhoods by city', function () {
    Neighbourhood::create(['name' => 'Akwa', 'slug' => 'akwa', 'city_id' => $this->city1->id]);
    Neighbourhood::create(['name' => 'Bonanjo', 'slug' => 'bonanjo', 'city_id' => $this->city1->id]);
    Neighbourhood::create(['name' => 'Bastos', 'slug' => 'bastos', 'city_id' => $this->city2->id]);

    $response = $this->getJson('/api/geo/neighbourhoods?city_id='.$this->city1->id);
    $response->assertStatus(200)->assertJsonCount(2);
});

it('can create, update and delete a neighbourhood', function () {
    $create = $this->postJson('/api/geo/neighbourhoods', [
        'name' => 'Makepe',
        'city_id' => $this->city1->id,
    ]);
    $create->assertStatus(201)->assertJsonPath('slug', 'makepe');
    $id = $create->json('id');

    $update = $this->putJson("/api/geo/neighbourhoods/{$id}", [
        'name' => 'Makepe Missoke',
    ]);
    $update->assertStatus(200)->assertJsonPath('name', 'Makepe Missoke');

    $delete = $this->deleteJson("/api/geo/neighbourhoods/{$id}");
    $delete->assertStatus(204);
});
