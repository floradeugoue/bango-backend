<?php

use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Database\Seeders\GeoSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->seed(SubscriptionCatalogSeeder::class);
    $this->seed(GeoSeeder::class);

    $this->landlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $this->prospect = User::factory()->create([
        'is_verified' => true,
    ]);

    $this->otherUser = User::factory()->create();
});

it('accepts and saves address in POST /listings and PATCH /listings/{id} but never exposes it in public listing', function () {
    Passport::actingAs($this->landlord);

    // 1. Création avec address
    $createRes = $this->postJson('/api/listings', [
        'title' => 'Bel appartement à Bastos',
        'description' => 'Superbe logement calme et sécurisé',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 350000,
        'bedrooms' => 3,
        'showers' => 2,
        'area' => 120,
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
        'landmark' => 'Près de l\'ambassade',
        'address' => '124 Rue des Palmiers, Entrée B, Porte 4',
        'lat' => 3.8900,
        'lng' => 11.5130,
    ])->assertCreated();

    $listingId = $createRes->json('data.id') ?? $createRes->json('id');
    $listing = Listing::findOrFail($listingId);
    expect($listing->address)->toBe('124 Rue des Palmiers, Entrée B, Porte 4');

    // Vérifier que la fiche publique /api/listings/{id} ne renvoie pas l'adresse
    Passport::actingAs($this->otherUser);
    $publicRes = $this->getJson("/api/listings/{$listingId}")->assertOk();
    expect($publicRes->json('data.address'))->toBeNull();

    // 2. Modification avec PATCH
    Passport::actingAs($this->landlord);
    $updateRes = $this->patchJson("/api/listings/{$listingId}", [
        'address' => '126 Rue des Palmiers, Nouvelle Entrée',
    ])->assertOk();

    expect($listing->fresh()->address)->toBe('126 Rue des Palmiers, Nouvelle Entrée');
});

it('exposes exact address, lat and lng only to the confirmed visit prospect in GET /visits/{id} and receipt', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'status' => ListingStatus::Published,
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'address' => 'Villa 42, Rue des Almadies, Derrière le Phare',
        'lat' => 14.7442,
        'lng' => -17.5164,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $this->landlord->id,
        'start_at' => now()->addDays(2)->setTime(15, 0),
        'end_at' => now()->addDays(2)->setTime(15, 30),
        'capacity' => 1,
        'booked_count' => 1,
        'status' => VisitSlotStatus::Open,
    ]);

    // Réservation confirmée
    $confirmedReservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'owner_id' => $this->landlord->id,
        'prospect_id' => $this->prospect->id,
        'fee_amount' => 5000,
        'status' => VisitReservationStatus::Confirmed,
        'paid_at' => now(),
    ]);

    // Réservation non confirmée (pending_payment)
    $pendingReservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'owner_id' => $this->landlord->id,
        'prospect_id' => $this->prospect->id,
        'fee_amount' => 5000,
        'status' => VisitReservationStatus::PendingPayment,
    ]);

    // 1. Prospect consulte sa visite confirmée : address, lat et lng exacts sont présents
    Passport::actingAs($this->prospect);
    $showConfirmedRes = $this->getJson("/api/visits/{$confirmedReservation->id}")->assertOk();
    $listingData = $showConfirmedRes->json('data.listing');

    expect($listingData['address'])->toBe('Villa 42, Rue des Almadies, Derrière le Phare')
        ->and((float) $listingData['lat'])->toBe(14.7442)
        ->and((float) $listingData['lng'])->toBe(-17.5164);

    // 2. Prospect consulte le reçu de la visite confirmée : address, lat et lng exacts sont présents
    $receiptRes = $this->getJson("/api/visits/{$confirmedReservation->id}/receipt")->assertOk();
    $receiptListing = $receiptRes->json('data.listing');

    expect($receiptListing['address'])->toBe('Villa 42, Rue des Almadies, Derrière le Phare')
        ->and((float) $receiptListing['lat'])->toBe(14.7442)
        ->and((float) $receiptListing['lng'])->toBe(-17.5164);

    // 3. Prospect consulte sa visite non confirmée : address, lat et lng exacts sont masqués
    $showPendingRes = $this->getJson("/api/visits/{$pendingReservation->id}")->assertOk();
    $pendingListingData = $showPendingRes->json('data.listing');

    expect($pendingListingData['address'])->toBeNull()
        ->and($pendingListingData['lat'])->toBeNull()
        ->and($pendingListingData['lng'])->toBeNull();

    // 4. Un tiers tente d'accéder à la réservation : refusé
    Passport::actingAs($this->otherUser);
    $this->getJson("/api/visits/{$confirmedReservation->id}")->assertForbidden();
});
