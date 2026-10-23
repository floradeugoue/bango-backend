<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Subscription\Subscription;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Delegation\AgentAcceptedNotification;
use App\Notifications\Delegation\AgentDeclinedNotification;
use App\Notifications\Delegation\AgentInvitationNotification;
use App\Notifications\Delegation\DelegationRevokedNotification;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->landlordProduct = SubscriptionProduct::where('code', 'landlord')->first();
    $this->landlordPrice = SubscriptionPrice::where('product_id', $this->landlordProduct->id)->where('cadence', 'monthly')->first();

    $this->agentProduct = SubscriptionProduct::where('code', 'agent')->first();
    $this->agentPrice = SubscriptionPrice::where('product_id', $this->agentProduct->id)->where('cadence', 'monthly')->first();

    $this->createLandlord = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'landlord',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $this->landlordProduct->id,
            'price_id' => $this->landlordPrice->id,
            'badge_type' => 'landlord',
            'cadence' => 'monthly',
            'amount' => $this->landlordPrice->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        return $user;
    };

    $this->createAgent = function (array $attributes = []): User {
        $user = User::factory()->create(array_merge([
            'badge' => 'agent',
            'is_verified' => true,
            'verification_lapsed' => false,
        ], $attributes));

        Subscription::create([
            'user_id' => $user->id,
            'product_id' => $this->agentProduct->id,
            'price_id' => $this->agentPrice->id,
            'badge_type' => 'agent',
            'cadence' => 'monthly',
            'amount' => $this->agentPrice->price_amount,
            'currency' => 'XAF',
            'state' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        return $user;
    };
});

test('landlord can invite an agent with active agent badge on a property', function (): void {
    Notification::fake();

    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    Passport::actingAs($landlord);

    $response = $this->postJson('/api/landlord/delegations', [
        'listing_id' => $listing->id,
        'agent_id' => $agent->id,
        'capabilities' => ['visits', 'reply', 'edit'],
        'expires_at' => now()->addMonths(3)->toIso8601String(),
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'bailleur_id',
                'agent_id',
                'listing_id',
                'status',
                'capabilities',
                'invited_at',
            ],
        ]);

    expect($response->json('data.status'))->toBe(DelegationStatus::Pending->value);

    // Notification is sent to agent
    Notification::assertSentTo($agent, AgentInvitationNotification::class);
});

test('landlord cannot invite a user who does not have an active agent badge', function (): void {
    $landlord = ($this->createLandlord)();
    $normalUser = User::factory()->create([
        'badge' => 'none',
        'is_verified' => true,
    ]);
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    Passport::actingAs($landlord);

    $response = $this->postJson('/api/landlord/delegations', [
        'listing_id' => $listing->id,
        'agent_id' => $normalUser->id,
        'capabilities' => ['visits'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['agent_id']);
});

test('cannot invite same agent twice if active or pending delegation exists', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
    ]);

    Passport::actingAs($landlord);

    $response = $this->postJson('/api/landlord/delegations', [
        'listing_id' => $listing->id,
        'agent_id' => $agent->id,
        'capabilities' => ['visits'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['agent_id']);
});

test('cannot exceed max 5 agents quota per listing', function (): void {
    $landlord = ($this->createLandlord)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
    ]);

    // Create 5 active delegations
    for ($i = 0; $i < 5; $i++) {
        $otherAgent = ($this->createAgent)();
        Delegation::create([
            'bailleur_id' => $landlord->id,
            'agent_id' => $otherAgent->id,
            'listing_id' => $listing->id,
            'status' => DelegationStatus::Active,
            'capabilities' => [DelegationCapability::Visits->value],
            'invited_at' => now()->subDay(),
            'answered_at' => now()->subHours(5),
        ]);
    }

    $sixthAgent = ($this->createAgent)();

    Passport::actingAs($landlord);

    $response = $this->postJson('/api/landlord/delegations', [
        'listing_id' => $listing->id,
        'agent_id' => $sixthAgent->id,
        'capabilities' => ['visits'],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['agent_id']);
});

test('agent can accept pending invitation and notification is sent to landlord', function (): void {
    Notification::fake();

    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
        'agent_id' => null,
    ]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'capabilities' => [DelegationCapability::Visits->value, DelegationCapability::Edit->value],
        'invited_at' => now(),
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/agent/invitations/{$delegation->id}/accept");

    $response->assertOk()
        ->assertJsonPath('data.status', DelegationStatus::Active->value);

    expect($delegation->fresh()->status)->toBe(DelegationStatus::Active);
    expect($delegation->fresh()->answered_at)->not->toBeNull();

    // Listing agent_id was populated
    expect($listing->fresh()->agent_id)->toBe($agent->id);

    // Landlord received acceptance notification
    Notification::assertSentTo($landlord, AgentAcceptedNotification::class);
});

test('agent cannot accept an invitation addressed to another agent', function (): void {
    $landlord = ($this->createLandlord)();
    $agentA = ($this->createAgent)();
    $agentB = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agentA->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
    ]);

    Passport::actingAs($agentB);

    $response = $this->postJson("/api/agent/invitations/{$delegation->id}/accept");

    $response->assertStatus(403);
});

test('agent can reject pending invitation and notification is sent to landlord', function (): void {
    Notification::fake();

    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
    ]);

    Passport::actingAs($agent);

    $response = $this->postJson("/api/agent/invitations/{$delegation->id}/reject", [
        'reason' => 'Indisponible sur ce secteur actuellement',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.status', DelegationStatus::Declined->value);

    expect($delegation->fresh()->status)->toBe(DelegationStatus::Declined);

    Notification::assertSentTo($landlord, AgentDeclinedNotification::class);
});

test('landlord can revoke delegation and agent loses access immediately', function (): void {
    Notification::fake();

    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
        'agent_id' => $agent->id,
    ]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value, DelegationCapability::Edit->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(12),
    ]);

    Passport::actingAs($landlord);

    $response = $this->deleteJson("/api/landlord/delegations/{$delegation->id}");

    $response->assertOk()
        ->assertJsonPath('data.status', DelegationStatus::Revoked->value);

    expect($delegation->fresh()->status)->toBe(DelegationStatus::Revoked);
    expect($delegation->fresh()->revoked_at)->not->toBeNull();
    expect($delegation->fresh()->revoked_by_id)->toBe($landlord->id);

    // Agent can no longer see the property in portfolio
    Passport::actingAs($agent);
    $portfolioRes = $this->getJson('/api/agent/properties');
    $portfolioRes->assertOk();
    expect($portfolioRes->json('data'))->toBeEmpty();

    Notification::assertSentTo($agent, DelegationRevokedNotification::class);
});

test('agent can view delegated properties in portfolio with effective capabilities', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();

    $listing = Listing::factory()->published()->create([
        'owner_id' => $landlord->id,
        'title' => 'Villa avec vue panoramique',
    ]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value, DelegationCapability::Reply->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(10),
    ]);

    Passport::actingAs($agent);

    $response = $this->getJson('/api/agent/properties');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'status',
                    'effective_capabilities',
                    'landlord' => ['id', 'name'],
                ],
            ],
            'meta',
        ]);

    $items = $response->json('data');
    expect($items)->toHaveCount(1);
    expect($items[0]['id'])->toBe($listing->id);
    expect($items[0]['effective_capabilities'])->toEqualCanonicalizing(['visits', 'reply']);
});

test('agent can view prospects on delegated properties only', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();

    $delegatedListing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);
    $otherListing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $delegatedListing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(10),
    ]);

    $prospect1 = User::factory()->create(['name' => 'Prospect Autorisé']);
    $prospect2 = User::factory()->create(['name' => 'Prospect Non Autorisé']);

    $slot1 = VisitSlot::create([
        'listing_id' => $delegatedListing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    $slot2 = VisitSlot::create([
        'listing_id' => $otherListing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDays(3)->setTime(10, 0),
        'end_at' => now()->addDays(3)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);

    VisitReservation::create([
        'listing_id' => $delegatedListing->id,
        'slot_id' => $slot1->id,
        'owner_id' => $landlord->id,
        'prospect_id' => $prospect1->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    VisitReservation::create([
        'listing_id' => $otherListing->id,
        'slot_id' => $slot2->id,
        'owner_id' => $landlord->id,
        'prospect_id' => $prospect2->id,
        'status' => VisitReservationStatus::Requested,
        'fee' => 5000,
        'currency' => 'XOF',
    ]);

    Passport::actingAs($agent);

    $response = $this->getJson('/api/agent/prospects');

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['prospect']['id'])->toBe($prospect1->id);
});

test('public users can search certified agents without exposing private information', function (): void {
    $agent = ($this->createAgent)([
        'name' => 'Jean Agent Immobilier',
        'city' => 'Douala',
    ]);

    $unverifiedUser = User::factory()->create([
        'name' => 'Faux Agent',
        'badge' => 'none',
        'is_verified' => false,
    ]);

    $response = $this->getJson('/api/agents/search?city=Douala');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'badge',
                    'is_verified',
                    'active_mandates_count',
                ],
            ],
            'meta',
        ]);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($agent->id);
    expect($ids)->not->toContain($unverifiedUser->id);

    // Check that sensitive fields like password or email are not exposed
    $first = $response->json('data.0');
    expect($first)->not->toHaveKeys(['password', 'remember_token', 'kyc_submissions', 'verification_lapsed']);
});

test('deleting a listing revokes its mandates, notifies the agent and hides them from lists', function (): void {
    Notification::fake();

    $landlord = ($this->createLandlord)(['name' => 'jeanpaul', 'display_name' => 'Jean-Paul Kamga']);
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    $active = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
        'answered_at' => now()->subHours(12),
    ]);

    Passport::actingAs($landlord);

    $this->getJson('/api/landlord/delegations')
        ->assertOk()
        ->assertJsonPath('data.0.bailleur_name', 'Jean-Paul Kamga');

    $this->deleteJson("/api/listings/{$listing->id}")->assertOk();

    $active->refresh();
    expect($active->status)->toBe(DelegationStatus::Revoked);
    expect($active->metadata['revocation_reason'])->toBe('annonce supprimée');
    Notification::assertSentTo($agent, DelegationRevokedNotification::class);

    expect($this->getJson('/api/landlord/delegations')->assertOk()->json('data'))->toBeEmpty();

    Passport::actingAs($agent);
    expect($this->getJson('/api/agent/invitations')->assertOk()->json('data'))->toBeEmpty();
});

test('revoking a mandate whose listing is already deleted does not crash', function (): void {
    $landlord = ($this->createLandlord)();
    $agent = ($this->createAgent)();
    $listing = Listing::factory()->published()->create(['owner_id' => $landlord->id]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now()->subDay(),
    ]);

    $listing->delete();
    Passport::actingAs($landlord);

    $this->deleteJson("/api/landlord/delegations/{$delegation->id}")
        ->assertOk()
        ->assertJsonPath('data.status', DelegationStatus::Revoked->value);
});
