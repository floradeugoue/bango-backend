<?php

use App\Enums\Delegation\DelegationStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Delegation\Delegation;
use App\Models\Delegation\VisitShare;
use App\Models\Listing\Listing;
use App\Models\Payment\AgentPayout;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Admin\Finance\AdminPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('Visit shares negotiation: landlord and agent can propose and accept percentages', function () {
    $landlord = User::factory()->create();
    $agent = User::factory()->create(['badge' => 'agent', 'is_verified' => true]);

    // 1. Bailleur propose 20%
    Passport::actingAs($landlord);
    $res1 = $this->postJson('/api/delegations/visit-share', [
        'agent_id' => $agent->id,
        'percent' => 20,
    ])->assertStatus(200);

    expect((float) $res1->json('data.offer_percent'))->toEqual(20.0);
    expect($res1->json('data.offer_by'))->toBe('landlord');
    expect($res1->json('data.agreed_percent'))->toBeNull();

    // 2. Bailleur ne peut pas accepter sa propre offre
    $this->postJson('/api/delegations/visit-share/accept', [
        'agent_id' => $agent->id,
    ])->assertStatus(403);

    // 3. Agent contre-propose 35%
    Passport::actingAs($agent);
    $res2 = $this->postJson('/api/delegations/visit-share', [
        'bailleur_id' => $landlord->id,
        'percent' => 35,
    ])->assertStatus(200);

    expect((float) $res2->json('data.offer_percent'))->toEqual(35.0);
    expect($res2->json('data.offer_by'))->toBe('agent');

    // 4. Bailleur accepte la contre-proposition
    Passport::actingAs($landlord);
    $res3 = $this->postJson('/api/delegations/visit-share/accept', [
        'agent_id' => $agent->id,
    ])->assertStatus(200);

    expect((float) $res3->json('data.agreed_percent'))->toEqual(35.0);
});

test('Agent invitation accept is blocked with 409 while agent proposal is pending, and accepted otherwise', function () {
    $landlord = User::factory()->create(['badge' => 'bailleur', 'is_verified' => true]);
    $agent = User::factory()->create(['badge' => 'agent', 'is_verified' => true]);
    $listing = Listing::factory()->create(['owner_id' => $landlord->id]);

    $delegation = Delegation::create([
        'bailleur_id' => $landlord->id,
        'agent_id' => $agent->id,
        'listing_id' => $listing->id,
        'status' => DelegationStatus::Pending,
        'invited_at' => now(),
        'expires_at' => now()->addDays(7),
    ]);

    // L'agent a fait une contre-proposition en attente
    VisitShare::create([
        'landlord_id' => $landlord->id,
        'agent_id' => $agent->id,
        'offer_percent' => 50,
        'offer_by' => 'agent',
        'offer_at' => now(),
    ]);

    Passport::actingAs($agent);
    // Tentative d'acceptation -> 409
    $this->postJson("/api/agent/invitations/{$delegation->id}/accept")
        ->assertStatus(409);

    // Le bailleur accepte la contre-proposition
    Passport::actingAs($landlord);
    $this->postJson('/api/delegations/visit-share/accept', [
        'agent_id' => $agent->id,
    ])->assertStatus(200);

    // Maintenant l'agent peut accepter
    Passport::actingAs($agent);
    $this->postJson("/api/agent/invitations/{$delegation->id}/accept")
        ->assertStatus(200);
});

test('Visit payment splits fee between landlord and agent according to agreed_percent', function () {
    $landlord = User::factory()->create(['badge' => 'bailleur', 'is_verified' => true]);
    $agent = User::factory()->create(['badge' => 'agent', 'is_verified' => true]);
    $prospect = User::factory()->create();
    $listing = Listing::factory()->create([
        'owner_id' => $landlord->id,
        'agent_id' => $agent->id,
        'visit_fee' => 10000,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'start_at' => now()->addDay(),
        'end_at' => now()->addDay()->addHour(),
        'capacity' => 5,
        'booked_count' => 1,
    ]);

    // Accord 40% pour l'agent
    VisitShare::create([
        'landlord_id' => $landlord->id,
        'agent_id' => $agent->id,
        'offer_percent' => 40,
        'agreed_percent' => 40,
        'offer_by' => 'landlord',
        'offer_at' => now(),
    ]);

    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $landlord->id,
        'agent_id' => $agent->id,
        'fee' => 10000,
        'status' => VisitReservationStatus::Confirmed,
        'paid_at' => now(),
    ]);

    $payoutService = app(AdminPayoutService::class);
    $landlordPayout = $payoutService->schedulePayoutForVisit($reservation);

    expect((float) $landlordPayout->amount)->toBe(6000.0);
    expect($landlordPayout->metadata['agent_cut'])->toEqual([
        'agent_id' => $agent->id,
        'percent' => 40.0,
        'amount' => 4000.0,
    ]);

    $agentPayout = AgentPayout::where('visit_reservation_id', $reservation->id)->first();
    expect($agentPayout)->not->toBeNull();
    expect((float) $agentPayout->amount)->toBe(4000.0);
    expect((float) $agentPayout->percent)->toBe(40.0);

    // Test /api/landlord/earnings
    Passport::actingAs($landlord);
    $resLandlord = $this->getJson('/api/landlord/earnings')->assertStatus(200);
    expect($resLandlord->json('summary.total_earnings'))->toEqual(6000.0);
    expect($resLandlord->json('movements.0.agent_cut.amount'))->toEqual(4000.0);

    // Test /api/agent/earnings
    Passport::actingAs($agent);
    $resAgent = $this->getJson('/api/agent/earnings')->assertStatus(200);
    expect($resAgent->json('summary.total_earnings'))->toEqual(4000.0);
    expect($resAgent->json('movements.0.percent'))->toEqual(40.0);
});
