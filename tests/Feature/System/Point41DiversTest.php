<?php

use App\Enums\Listing\ListingKind;
use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Admin\SystemConfiguration;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('property analytics includes messages and paid visits in totals and daily series', function () {
    $owner = User::factory()->create(['name' => 'Owner Bailleur']);
    $prospect = User::factory()->create(['name' => 'Prospect']);
    Passport::actingAs($owner);

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'title' => 'Appartement Point 41',
        'mode' => ListingMode::Rent,
        'kind' => ListingKind::Flat,
        'status' => ListingStatus::Published,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'price' => 200000,
        'currency' => 'XAF',
    ]);

    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $owner->id,
        'start_at' => Carbon::now()->addDays(2)->setHour(10)->setMinute(0),
        'end_at' => Carbon::now()->addDays(2)->setHour(11)->setMinute(0),
        'capacity' => 5,
        'status' => 'open',
    ]);

    // 1. Create a conversation linked to the listing and add messages
    $conversation = Conversation::create([
        'type' => 'direct',
        'title' => 'Demande de renseignements',
        'listing_id' => $listing->id,
        'created_by_id' => $prospect->id,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $prospect->id,
        'type' => 'text',
        'body' => 'Bonjour, le bien est-il toujours disponible ?',
        'created_at' => Carbon::now(),
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'sender_id' => $owner->id,
        'type' => 'text',
        'body' => 'Oui tout à fait !',
        'created_at' => Carbon::now(),
    ]);

    // 2. Create a paid visit reservation
    VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $owner->id,
        'fee' => 5000,
        'currency' => 'XAF',
        'status' => VisitReservationStatus::Confirmed->value,
        'paid_at' => Carbon::now(),
        'created_at' => Carbon::now(),
    ]);

    // Also create a free visit reservation (fee = 0) to ensure only paid ones count for paid_visits
    VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $prospect->id,
        'owner_id' => $owner->id,
        'fee' => 0,
        'currency' => 'XAF',
        'status' => VisitReservationStatus::Confirmed->value,
        'created_at' => Carbon::now(),
    ]);

    $response = getJson("/api/landlord/properties/{$listing->id}/analytics");

    $response->assertOk()
        ->assertJsonPath('data.listing_id', (string) $listing->id)
        ->assertJsonPath('data.totals.messages', 2)
        ->assertJsonPath('data.totals.messages_count', 2)
        ->assertJsonPath('data.totals.paid_visits', 1)
        ->assertJsonPath('data.totals.paid_visits_count', 1);

    // Verify series includes messages and paid_visits keys
    $series = $response->json('data.series');
    $lastDay = end($series);
    expect($lastDay)->toHaveKeys(['messages', 'paid_visits']);
    expect($lastDay['messages'])->toBe(2);
    expect($lastDay['paid_visits'])->toBe(1);
});

test('system version includes maintenance indicators when system is operational or in maintenance', function () {
    // 1. In standard operational state
    $resNormal = getJson('/api/system/version?platform=android');
    $resNormal->assertOk()
        ->assertJsonPath('data.maintenance_mode', false)
        ->assertJsonPath('data.is_maintenance', false);

    // 2. When maintenance mode is activated in SystemConfiguration
    SystemConfiguration::setValue(
        'service.maintenance_mode',
        true,
        'maintenance',
        'boolean',
        'Mode maintenance activé'
    );
    SystemConfiguration::setValue(
        'service.maintenance_message',
        'Mise à jour majeure du serveur en cours.',
        'maintenance',
        'string',
        'Message de maintenance'
    );

    $resMaint = getJson('/api/system/version?platform=android');
    $resMaint->assertOk()
        ->assertJsonPath('data.maintenance_mode', true)
        ->assertJsonPath('data.is_maintenance', true)
        ->assertJsonPath('data.maintenance_message', 'Mise à jour majeure du serveur en cours.');
});
