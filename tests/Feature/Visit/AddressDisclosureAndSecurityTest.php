<?php

use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Auth\Role;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->agent = User::factory()->create([
        'badge' => 'agent',
    ]);

    $this->otherAgent = User::factory()->create([
        'badge' => 'agent',
    ]);

    $this->prospect = User::factory()->create([
        'badge' => 'none',
        'phone' => '+237699112233',
    ]);

    $this->randomUser = User::factory()->create([
        'badge' => 'none',
    ]);

    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->admin = User::factory()->create([
        'badge' => 'none',
        'role_id' => $adminRole->id,
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'agent_id' => $this->agent->id,
        'title' => 'Villa Secrète Bonapriso',
        'address' => '142 Rue des Palmiers, Bonapriso, Douala',
        'visit_fee' => 5000,
        'currency' => 'XAF',
    ]);

    $this->slot = VisitSlot::create([
        'listing_id' => $this->listing->id,
        'owner_id' => $this->owner->id,
        'start_at' => now()->addDays(2)->setTime(10, 0),
        'end_at' => now()->addDays(2)->setTime(10, 30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => VisitSlotStatus::Open,
    ]);
});

test('exact address is masked in listing detail for public and prospects without confirmed visit', function (): void {
    // 1. Consultation publique non connectée
    $publicResponse = $this->getJson("/api/listings/{$this->listing->id}");
    $publicResponse->assertOk();
    expect($publicResponse->json('data.address'))->toBeNull();

    // 2. Consultation par un utilisateur connecté
    Passport::actingAs($this->randomUser);
    $userResponse = $this->getJson("/api/listings/{$this->listing->id}");
    $userResponse->assertOk();
    expect($userResponse->json('data.address'))->toBeNull();

    // 3. Consultation par un prospect ayant une réservation pending_payment (non encore payée/confirmée)
    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::PendingPayment,
        'lock_expires_at' => now()->addMinutes(10),
        'fee' => 5000,
    ]);

    Passport::actingAs($this->prospect);
    $pendingResponse = $this->getJson("/api/listings/{$this->listing->id}");
    $pendingResponse->assertOk();
    expect($pendingResponse->json('data.address'))->toBeNull();
});

test('exact address is revealed in listing detail once prospect has a confirmed visit', function (): void {
    VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::Confirmed,
        'fee' => 5000,
        'paid_at' => now(),
    ]);

    Passport::actingAs($this->prospect);
    $response = $this->getJson("/api/listings/{$this->listing->id}");
    $response->assertOk();

    expect($response->json('data.address'))->toBe('142 Rue des Palmiers, Bonapriso, Douala');
});

test('owner, assigned agent and admin always have access to the exact address', function (): void {
    // 1. Propriétaire
    Passport::actingAs($this->owner);
    $ownerRes = $this->getJson("/api/listings/{$this->listing->id}");
    $ownerRes->assertOk();
    expect($ownerRes->json('data.address'))->toBe('142 Rue des Palmiers, Bonapriso, Douala');

    // 2. Agent mandaté
    Passport::actingAs($this->agent);
    $agentRes = $this->getJson("/api/listings/{$this->listing->id}");
    $agentRes->assertOk();
    expect($agentRes->json('data.address'))->toBe('142 Rue des Palmiers, Bonapriso, Douala');

    // 3. Administrateur
    Passport::actingAs($this->admin);
    $adminRes = $this->getJson("/api/listings/{$this->listing->id}");
    $adminRes->assertOk();
    expect($adminRes->json('data.address'))->toBe('142 Rue des Palmiers, Bonapriso, Douala');

    // 4. Agent non mandaté sur ce bien -> adresse masquée
    Passport::actingAs($this->otherAgent);
    $otherAgentRes = $this->getJson("/api/listings/{$this->listing->id}");
    $otherAgentRes->assertOk();
    expect($otherAgentRes->json('data.address'))->toBeNull();
});

test('address in visit reservation detail and receipt is revealed only if confirmed', function (): void {
    $reservation = VisitReservation::create([
        'listing_id' => $this->listing->id,
        'slot_id' => $this->slot->id,
        'prospect_id' => $this->prospect->id,
        'owner_id' => $this->owner->id,
        'status' => VisitReservationStatus::PendingPayment,
        'lock_expires_at' => now()->addMinutes(12),
        'fee' => 5000,
        'currency' => 'XAF',
    ]);

    Passport::actingAs($this->prospect);

    // 1. En attente de paiement -> adresse null
    $resBefore = $this->getJson("/api/visits/{$reservation->id}");
    $resBefore->assertOk();
    expect($resBefore->json('data.listing.address'))->toBeNull()
        ->and($resBefore->json('data.can_view_address'))->toBeFalse();

    // 2. Confirmation de la visite -> adresse révélée
    $reservation->status = VisitReservationStatus::Confirmed;
    $reservation->paid_at = now();
    $reservation->save();

    $resAfter = $this->getJson("/api/visits/{$reservation->id}");
    $resAfter->assertOk();
    expect($resAfter->json('data.listing.address'))->toBe('142 Rue des Palmiers, Bonapriso, Douala')
        ->and($resAfter->json('data.can_view_address'))->toBeTrue();

    // 3. Reçu officiel de visite
    $receiptRes = $this->getJson("/api/visits/{$reservation->id}/receipt");
    $receiptRes->assertOk();
    expect($receiptRes->json('data.listing.address'))->toBe('142 Rue des Palmiers, Bonapriso, Douala');
});
