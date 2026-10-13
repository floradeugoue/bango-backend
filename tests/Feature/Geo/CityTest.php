<?php

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Role;
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

    $this->currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $this->country1 = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $this->currency->id]);
    $this->country2 = Country::create(['name' => 'Gabon', 'code' => 'GA', 'iso2' => 'GA', 'iso3' => 'GAB', 'currency_id' => $this->currency->id]);
});

it('can list and filter cities by country', function () {
    City::create(['name' => 'Douala', 'slug' => 'douala', 'country_id' => $this->country1->id]);
    City::create(['name' => 'Yaoundé', 'slug' => 'yaounde', 'country_id' => $this->country1->id]);
    City::create(['name' => 'Libreville', 'slug' => 'libreville', 'country_id' => $this->country2->id]);

    $all = $this->getJson('/api/geo/cities');
    $all->assertStatus(200)->assertJsonCount(3);

    $filtered = $this->getJson('/api/geo/cities?country_id='.$this->country1->id);
    $filtered->assertStatus(200)->assertJsonCount(2);
});

it('can create, update and delete a city', function () {
    $create = $this->postJson('/api/geo/cities', [
        'name' => 'Bafoussam',
        'country_id' => $this->country1->id,
    ]);
    $create->assertStatus(201)->assertJsonPath('slug', 'bafoussam');
    $id = $create->json('id');

    $update = $this->putJson("/api/geo/cities/{$id}", [
        'name' => 'Bafoussam Ville',
    ]);
    $update->assertStatus(200)->assertJsonPath('name', 'Bafoussam Ville');

    $delete = $this->deleteJson("/api/geo/cities/{$id}");
    $delete->assertStatus(204);
});
