<?php

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
});

it('can list and filter currencies', function () {
    Currency::create(['name' => 'Franc CFA BEAC', 'code' => 'XAF', 'symbol' => 'FCFA', 'is_active' => true]);
    Currency::create(['name' => 'Dollar US', 'code' => 'USD', 'symbol' => '$', 'is_active' => false]);

    $response = $this->getJson('/api/geo/currencies');
    $response->assertStatus(200)->assertJsonCount(2);

    $filtered = $this->getJson('/api/geo/currencies?is_active=1');
    $filtered->assertStatus(200)->assertJsonCount(1)->assertJsonPath('0.code', 'XAF');
});

it('can create, update and delete a currency as admin', function () {
    $response = $this->postJson('/api/geo/currencies', [
        'name' => 'Euro',
        'code' => 'EUR',
        'symbol' => '€',
        'is_active' => true,
    ]);
    $response->assertStatus(201)->assertJsonPath('code', 'EUR');
    $id = $response->json('id');

    $update = $this->putJson("/api/geo/currencies/{$id}", [
        'name' => 'Eurozone Euro',
    ]);
    $update->assertStatus(200)->assertJsonPath('name', 'Eurozone Euro');

    $delete = $this->deleteJson("/api/geo/currencies/{$id}");
    $delete->assertStatus(204);
});
