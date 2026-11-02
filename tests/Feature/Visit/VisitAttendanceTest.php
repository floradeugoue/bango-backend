<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Auth\Role;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

    $this->owner = User::factory()->create(['badge' => 'landlord']);
    $this->agentWithVisits = User::factory()->create(['badge' => 'agent']);
    $this->agentWithoutVisits = User::factory()->create(['badge' => 'agent']);
    $this->prospect = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->admin = User::factory()->create(['role_id' => $adminRole->id]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement Attendance Test',
        'visit_fee' => 2000,
        'currency' => 'XAF',
    ]);

    // Délégation active avec capacité visits
    Delegation::create([
        'bailleur_id' => $this->owner->id,
        'agent_id' => $this->agentWithVisits->id,
        'listing_id' => $this->listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Visits->value],
        'invited_at' => now(),
        'answered_at' => now(),
    ]);

    // Délégation active sans capacité visits (ex: edit uniquement)
    Delegation::create([
        'bailleur_id' => $this->owner->id,
        'agent_id' => $this->agentWithoutVisits->id,
        'listing_id' => $this->listing->id,
        'status' => DelegationStatus::Active,
        'capabilities' => [DelegationCapability::Edit->value],
        'invited_at' => now(),
        'answered_at' => now(),
    ]);

    // Créneau terminé dans le passé
    $this->pastSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->subHours(2),
        'end_at' => now()->subHours(1),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Closed,
    ]);

    // Créneau futur (pas encore terminé)
    $this->futureSlot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addHours(1),
        'end_at' => now()->addHours(2),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    // Réservation confirmée sur le créneau passé
    $this->confirmedPastReservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->pastSlot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 2000,
        'currency' => 'XAF',
        'paid_at' => now()->subHours(3),
    ]);

    // Réservation confirmée sur le créneau futur
    $this->confirmedFutureReservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->futureSlot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 2000,
        'currency' => 'XAF',
        'paid_at' => now()->subHours(1),
    ]);
});

test('attendance refuses if slot has not ended yet', function (): void {
    Passport::actingAs($this->prospect);

    $response = $this->postJson("/api/visits/{$this->confirmedFutureReservation->id}/attendance", [
        'attendance' => 'came',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['attendance']);
});

test('attendance refuses unauthorized users and agent without visits capability', function (): void {
    // Un utilisateur étranger
    Passport::actingAs($this->stranger);
    $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'came',
    ])->assertStatus(403);

    // Un agent délégué mais sans la capacité visits
    Passport::actingAs($this->agentWithoutVisits);
    $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'came',
    ])->assertStatus(403);
});

test('attendance records prospect attendance and returns dates in resource', function (): void {
    Passport::actingAs($this->prospect);

    $response = $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'came',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.prospect_attendance.attendance', 'came')
        ->assertJsonPath('data.landlord_attendance', null)
        ->assertJsonPath('data.is_disputed', false);

    expect($response->json('data.prospect_attendance.marked_at'))->not->toBeNull();

    $this->confirmedPastReservation->refresh();
    expect($this->confirmedPastReservation->prospect_attendance)->toBe('came')
        ->and($this->confirmedPastReservation->prospect_attendance_at)->not->toBeNull();
});

test('attendance records landlord attendance and agent with visits capability', function (): void {
    // Le bailleur enregistre no_show
    Passport::actingAs($this->owner);

    $response = $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'no_show',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.landlord_attendance.attendance', 'no_show')
        ->assertJsonPath('data.prospect_attendance', null);

    // L'agent mandaté avec la capacité visits peut également enregistrer / modifier la réponse côté bailleur
    Passport::actingAs($this->agentWithVisits);
    $response2 = $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'came',
    ]);

    $response2->assertOk()
        ->assertJsonPath('data.landlord_attendance.attendance', 'came');
});

test('contradictory attendances put the visit in dispute and make it visible in back-office', function (): void {
    // 1. Le prospect déclare être venu ('came')
    Passport::actingAs($this->prospect);
    $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'came',
    ])->assertOk();

    // 2. Le bailleur déclare que le prospect n'est pas venu ('no_show')
    Passport::actingAs($this->owner);
    $response = $this->postJson("/api/visits/{$this->confirmedPastReservation->id}/attendance", [
        'attendance' => 'no_show',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.prospect_attendance.attendance', 'came')
        ->assertJsonPath('data.landlord_attendance.attendance', 'no_show')
        ->assertJsonPath('data.is_disputed', true);

    expect($response->json('data.disputed_at'))->not->toBeNull()
        ->and($response->json('data.dispute_reason'))->toContain('Contradiction');

    $this->confirmedPastReservation->refresh();
    expect($this->confirmedPastReservation->disputed_at)->not->toBeNull();

    // 3. Vérification de la visibilité côté back-office (AdminListingVisitController)
    Passport::actingAs($this->admin);
    $adminResponse = $this->getJson('/api/admin/listings/visits?disputed=true');
    $adminResponse->assertOk();

    $items = collect($adminResponse->json('items'));
    $disputedVisit = $items->firstWhere('id', $this->confirmedPastReservation->id);

    expect($disputedVisit)->not->toBeNull()
        ->and($disputedVisit['is_disputed'])->toBeTrue()
        ->and($disputedVisit['dispute_reason'])->not->toBeNull()
        ->and($disputedVisit['prospect_attendance']['attendance'])->toBe('came')
        ->and($disputedVisit['landlord_attendance']['attendance'])->toBe('no_show');

    // 4. Recherche par référence courte (ex: 8 premiers caractères) ou payment_reference
    $this->confirmedPastReservation->payment_reference = 'VISIT-CREDIT-01a16dfa-0a10-7371-971c-5e2ece80cafa';
    $this->confirmedPastReservation->save();

    // Recherche avec les 8 premiers caractères seulement : "01a16dfa"
    $searchShortRes = $this->getJson('/api/admin/listings/visits?q=01a16dfa');
    $searchShortRes->assertOk();
    $foundShort = collect($searchShortRes->json('items'))->firstWhere('id', $this->confirmedPastReservation->id);
    expect($foundShort)->not->toBeNull();
    expect($foundShort['short_reference'])->toBe('01A16DFA');
});
