<?php

use App\Models\Landlord\LandlordSlotRule;
use App\Models\Landlord\LandlordUnavailability;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Visit\VisitSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->landlord = User::factory()->create([
        'account_type' => 'pro',
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $this->prospect = User::factory()->create([
        'account_type' => 'personal',
    ]);
});

test('landlord can get default slot rules and update them', function () {
    Passport::actingAs($this->landlord);

    // 1. GET /api/landlord/slot-rules (initial defaults)
    $getRes = $this->getJson('/api/landlord/slot-rules')->assertOk();
    expect($getRes->json('data.minimum_hours'))->toBe(4)
        ->and($getRes->json('data.auto_confirm'))->toBeFalse();

    // 2. PUT /api/landlord/slot-rules
    $putRes = $this->putJson('/api/landlord/slot-rules', [
        'minimum_hours' => 6,
        'grouped' => true,
        'group_size' => 8,
        'auto_confirm' => true,
        'days' => [
            ['day' => 1, 'from' => '10:00', 'to' => '17:00'],
            ['day' => 3, 'from' => '14:00', 'to' => '18:00'],
            ['day' => 0, 'closed' => true],
        ],
    ])->assertOk();

    expect($putRes->json('data.minimum_hours'))->toBe(6)
        ->and($putRes->json('data.grouped'))->toBeTrue()
        ->and($putRes->json('data.group_size'))->toBe(8)
        ->and($putRes->json('data.auto_confirm'))->toBeTrue()
        ->and($putRes->json('data.days'))->toHaveCount(3);

    // Vérification en base
    $rule = LandlordSlotRule::where('user_id', $this->landlord->id)->first();
    expect($rule)->not->toBeNull()
        ->and($rule->minimum_hours)->toBe(6)
        ->and($rule->auto_confirm)->toBeTrue();
});

test('landlord slot rules are applied by default when creating a new listing', function () {
    // Définir les règles du bailleur
    LandlordSlotRule::create([
        'user_id' => $this->landlord->id,
        'minimum_hours' => 12,
        'grouped' => true,
        'group_size' => 10,
        'auto_confirm' => true,
        'days' => [
            ['day' => 1, 'from' => '09:00', 'to' => '16:00'],
        ],
    ]);

    // Publier un nouveau bien
    Passport::actingAs($this->landlord);
    $storeRes = $this->postJson('/api/listings', [
        'title' => 'Superbe appartement test règles',
        'description' => 'Description détaillée du bien immobilier',
        'mode' => 'rent',
        'kind' => 'flat',
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 60,
        'price' => 150000,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ])->assertStatus(201);

    $listingId = $storeRes->json('data.id');
    $listing = Listing::findOrFail($listingId);

    expect($listing->auto_confirm)->toBeTrue()
        ->and($listing->attributes['minimum_hours'])->toBe(12)
        ->and($listing->attributes['grouped'])->toBeTrue()
        ->and($listing->attributes['group_size'])->toBe(10)
        ->and($listing->attributes['auto_confirm'])->toBeTrue();
});

test('auto_confirm can be updated per property on PUT /properties/{id}/slots', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'auto_confirm' => false,
    ]);

    Passport::actingAs($this->landlord);

    $putRes = $this->putJson("/api/landlord/properties/{$listing->id}/slots", [
        'auto_confirm' => true,
        'minimum_hours' => 2,
    ])->assertOk();

    expect($listing->fresh()->auto_confirm)->toBeTrue()
        ->and($listing->fresh()->attributes['auto_confirm'])->toBeTrue();
});

test('reservation is auto_confirmed when auto_confirm is enabled', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'visit_fee' => 0,
        'auto_confirm' => true,
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $this->landlord->id,
        'start_at' => now()->addDay()->setHour(14)->setMinute(0),
        'end_at' => now()->addDay()->setHour(14)->setMinute(30),
        'capacity' => 1,
        'booked_count' => 0,
        'status' => \App\Enums\Visit\VisitSlotStatus::Open,
    ]);

    Passport::actingAs($this->prospect);

    $res = $this->postJson("/api/listings/{$listing->id}/visits", [
        'slot_id' => $slot->id,
    ])->assertStatus(201);

    expect($res->json('data.status'))->toBe('confirmed');
    expect(VisitReservation::find($res->json('data.id'))->status->value)->toBe('confirmed');
});

test('landlord unavailabilities can be created, listed, and deleted, and hide slots', function () {
    Passport::actingAs($this->landlord);

    $from = now()->addDays(2)->setHour(8)->setMinute(0)->toISOString();
    $to = now()->addDays(3)->setHour(20)->setMinute(0)->toISOString();

    // 1. POST /api/landlord/unavailability
    $postRes = $this->postJson('/api/landlord/unavailability', [
        'from' => $from,
        'to' => $to,
    ])->assertStatus(201);

    $unavailabilityId = $postRes->json('data.id');
    expect($unavailabilityId)->not->toBeNull();

    // 2. GET /api/landlord/unavailability
    $getRes = $this->getJson('/api/landlord/unavailability')->assertOk();
    expect($getRes->json('data'))->toHaveCount(1)
        ->and($getRes->json('data.0.id'))->toBe($unavailabilityId);

    // 3. Vérifier l'impact sur les créneaux disponibles
    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'uses_agenda' => true,
    ]);

    $slotService = app(VisitSlotService::class);
    $availableDays = $slotService->getAvailableDays($listing, null, now());

    // Le jour dans la plage de congés (addDays(2)) ne doit avoir aucun créneau disponible
    $dayKey = now()->addDays(2)->format('Y-m-d');
    $dayConfig = collect($availableDays)->firstWhere('date', $dayKey);
    if ($dayConfig) {
        $availableSlots = collect($dayConfig['slots'])->where('available', true);
        expect($availableSlots)->toBeEmpty();
    }

    // 4. DELETE /api/landlord/unavailability/{id}
    $delRes = $this->deleteJson("/api/landlord/unavailability/{$unavailabilityId}")->assertOk();
    expect(LandlordUnavailability::find($unavailabilityId))->toBeNull();

    // Ou DELETE avec { from, to }
    $unavail2 = LandlordUnavailability::create([
        'user_id' => $this->landlord->id,
        'from' => $from,
        'to' => $to,
    ]);
    $delBodyRes = $this->deleteJson('/api/landlord/unavailability', [
        'from' => $from,
        'to' => $to,
    ])->assertOk();
    expect($delBodyRes->json('deleted'))->toBe(1);
});
