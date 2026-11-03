<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->owner = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $this->agent = User::factory()->create([
        'badge' => 'agent',
        'is_verified' => true,
    ]);

    $agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $agentPrice = SubscriptionPrice::where('product_id', $agentProduct->id)->where('cadence', 'monthly')->first();

    Subscription::create([
        'user_id' => $this->agent->id,
        'product_id' => $agentProduct->id,
        'price_id' => $agentPrice->id,
        'badge_type' => 'agent',
        'cadence' => 'monthly',
        'amount' => $agentPrice->price_amount,
        'currency' => 'XAF',
        'state' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'auto_renew' => true,
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'price' => 150000,
        'deposit_months' => 2,
        'advance_months' => 1,
        'visit_fee' => 5000,
        'charges' => 10000,
        'negotiable' => true,
        'title' => 'Bel appartement à Akwa',
    ]);

    // Délégation active conférant la capacité 'edit' à l'agent
    $this->delegation = Delegation::create([
        'bailleur_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'listing_id' => $this->listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Edit->value, DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(5),
    ]);
});

test('owner can update financial fields and listing details', function (): void {
    Passport::actingAs($this->owner);

    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        'title' => 'Nouveau titre par le propriétaire',
        'price' => 180000,
        'deposit_months' => 3,
        'advance_months' => 2,
        'visit_fee' => 6000,
        'charges' => 15000,
        'negotiable' => false,
    ]);

    $response->assertOk();

    $this->listing->refresh();
    expect($this->listing->title)->toBe('Nouveau titre par le propriétaire')
        ->and((float) $this->listing->price)->toBe(180000.0)
        ->and($this->listing->deposit_months)->toBe(3)
        ->and($this->listing->advance_months)->toBe(2)
        ->and((float) $this->listing->visit_fee)->toBe(6000.0)
        ->and((float) $this->listing->charges)->toBe(15000.0)
        ->and($this->listing->negotiable)->toBeFalse();
});

test('agent with edit capability is rejected with 403 price-owner-only when attempting to change price', function (): void {
    Passport::actingAs($this->agent);

    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        'price' => 200000,
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('kind', 'price-owner-only');

    $this->listing->refresh();
    expect((float) $this->listing->price)->toBe(150000.0);
});

test('agent with edit capability is rejected with 403 price-owner-only for any of the 6 financial fields', function (string $field, mixed $value): void {
    Passport::actingAs($this->agent);

    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        $field => $value,
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('kind', 'price-owner-only');
})->with([
    ['price', 250000],
    ['deposit_months', 4],
    ['advance_months', 3],
    ['visit_fee', 8000],
    ['charges', 20000],
    ['negotiable', false],
]);

test('agent with edit capability can update non-financial fields without error', function (): void {
    Passport::actingAs($this->agent);

    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        'title' => 'Titre mis à jour par l\'agent',
        'description' => 'Description détaillée mise à jour par l\'agent délégué.',
        'landmark' => 'Face glacier moderne',
        'address' => 'Rue Tobner, Akwa',
    ]);

    $response->assertOk();

    $this->listing->refresh();
    expect($this->listing->title)->toBe('Titre mis à jour par l\'agent')
        ->and($this->listing->description)->toBe('Description détaillée mise à jour par l\'agent délégué.')
        ->and($this->listing->location)->toBe('Face glacier moderne')
        ->and($this->listing->address)->toBe('Rue Tobner, Akwa')
        ->and((float) $this->listing->price)->toBe(150000.0); // Le prix reste inchangé
});
