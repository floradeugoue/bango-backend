<?php

use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Operator;
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

    $currency = Currency::create(['name' => 'Franc CFA', 'code' => 'XAF']);
    $this->country1 = Country::create(['name' => 'Cameroun', 'code' => 'CM', 'iso2' => 'CM', 'iso3' => 'CMR', 'currency_id' => $currency->id]);
    $this->country2 = Country::create(['name' => 'Côte d’Ivoire', 'code' => 'CI', 'iso2' => 'CI', 'iso3' => 'CIV', 'currency_id' => $currency->id]);
});

it('can list and filter operators by country', function () {
    Operator::create(['name' => 'Orange CM', 'code' => 'orange_cm', 'country_id' => $this->country1->id]);
    Operator::create(['name' => 'MTN CM', 'code' => 'mtn_cm', 'country_id' => $this->country1->id]);
    Operator::create(['name' => 'Moov CI', 'code' => 'moov_ci', 'country_id' => $this->country2->id]);

    $all = $this->getJson('/api/geo/operators');
    $all->assertStatus(200)->assertJsonCount(3);

    $filtered = $this->getJson('/api/geo/operators?country_id='.$this->country1->id);
    $filtered->assertStatus(200)->assertJsonCount(2);
});

it('can create, update and delete an operator', function () {
    $create = $this->postJson('/api/geo/operators', [
        'name' => 'Camtel',
        'code' => 'camtel_cm',
        'country_id' => $this->country1->id,
        'logo_url' => 'https://example.com/camtel.png',
    ]);
    $create->assertStatus(201)->assertJsonPath('code', 'camtel_cm');
    $id = $create->json('id');

    $update = $this->putJson("/api/geo/operators/{$id}", [
        'name' => 'Camtel Blue',
    ]);
    $update->assertStatus(200)->assertJsonPath('name', 'Camtel Blue');

    $delete = $this->deleteJson("/api/geo/operators/{$id}");
    $delete->assertStatus(204);
});
