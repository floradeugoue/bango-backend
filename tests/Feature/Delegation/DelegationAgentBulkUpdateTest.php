<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('DelegationResource includes agent details and PUT /delegations/agents/{agent_id} bulk updates capabilities', function () {
    $landlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $agent = User::factory()->create([
        'badge' => 'agent',
        'is_verified' => true,
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'avatar_url' => 'https://example.com/avatar.jpg',
    ]);

    $listing1 = Listing::factory()->create(['owner_id' => $landlord->id]);
    $listing2 = Listing::factory()->create(['owner_id' => $landlord->id]);

    $del1 = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing1->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
    ]);

    $del2 = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing2->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
    ]);

    // 1. Test GET /api/landlord/delegations includes agent details
    Passport::actingAs($landlord);

    $response = $this->getJson('/api/landlord/delegations');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(2);
    expect($data[0]['agent'])->not->toBeNull();
    expect($data[0]['agent']['id'])->toBe($agent->id);
    expect($data[0]['agent']['city'])->toBe('Douala');
    expect($data[0]['agent']['neighbourhood'])->toBe('Bonapriso');
    expect($data[0]['agent']['avatar_url'])->toBe('https://example.com/avatar.jpg');
    expect($data[0]['agent']['has_agent_badge'])->toBeTrue();

    // 2. Test PUT /api/landlord/delegations/agents/{agent_id} bulk updates
    $updateResponse = $this->putJson("/api/landlord/delegations/agents/{$agent->id}", [
        'capabilities' => ['visits', 'reply'],
        'listing_ids' => [$listing1->id, $listing2->id],
    ]);

    $updateResponse->assertOk();
    expect($del1->fresh()->capabilities)->toBe(['visits', 'reply']);
    expect($del2->fresh()->capabilities)->toBe(['visits', 'reply']);
});
