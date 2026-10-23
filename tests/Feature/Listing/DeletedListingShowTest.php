<?php

use App\Enums\Listing\ListingMode;
use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);
});

test('non-existent listing returns 404', function (): void {
    $fakeUuid = '00000000-0000-0000-0000-000000000000';
    $response = $this->getJson("/api/listings/{$fakeUuid}");

    $response->assertStatus(404);
});

test('deleted listing returns 410 with title, location, mode, removed_at and reason withdrawn (IM-21)', function (): void {
    $listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Villa Kotto',
        'city' => 'Douala',
        'neighbourhood' => 'Kotto',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Published,
    ]);

    $listing->delete();

    $response = $this->getJson("/api/listings/{$listing->id}");

    $response->assertStatus(410);
    $response->assertJson([
        'title' => 'Villa Kotto',
        'city' => 'Douala',
        'neighbourhood' => 'Kotto',
        'mode' => 'rent',
        'reason' => 'withdrawn',
    ]);

    expect($response->json('removed_at'))->not->toBeNull();
});

test('deleted listing that was taken returns reason rented (IM-21)', function (): void {
    $listing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement Kotto',
        'city' => 'Douala',
        'neighbourhood' => 'Kotto',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Taken,
    ]);

    $listing->delete();

    $response = $this->getJson("/api/listings/{$listing->id}");

    $response->assertStatus(410);
    $response->assertJson([
        'title' => 'Appartement Kotto',
        'city' => 'Douala',
        'neighbourhood' => 'Kotto',
        'mode' => 'rent',
        'reason' => 'rented',
    ]);
});

test('deleted listing that was moderated returns reason moderated (IM-21)', function (): void {
    $listing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Studio Kotto',
        'city' => 'Douala',
        'neighbourhood' => 'Kotto',
        'mode' => ListingMode::Rent,
        'status' => ListingStatus::Published,
        'withdrawn_by_bango' => true,
    ]);

    $listing->delete();

    $response = $this->getJson("/api/listings/{$listing->id}");

    $response->assertStatus(410);
    $response->assertJson([
        'title' => 'Studio Kotto',
        'city' => 'Douala',
        'neighbourhood' => 'Kotto',
        'mode' => 'rent',
        'reason' => 'moderated',
    ]);
});
