<?php

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingSave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->userA = User::factory()->create();
    $this->userB = User::factory()->create();

    $this->listing1 = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Villa Savoir',
        'price' => 500000,
        'saves_count' => 0,
    ]);

    $this->listing2 = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Studio Meublé',
        'price' => 120000,
        'saves_count' => 0,
    ]);
});

test('authenticated user can save a published listing', function (): void {
    Passport::actingAs($this->userA);
    $response = $this->postJson("/api/listings/{$this->listing1->id}/save");

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'saved' => true,
        ]);

    $this->assertDatabaseHas('listing_saves', [
        'user_id' => $this->userA->id,
        'listing_id' => $this->listing1->id,
    ]);

    expect($this->listing1->fresh()->saves_count)->toBe(1);
});

test('saving an already saved listing is idempotent and prevents duplicates', function (): void {
    Passport::actingAs($this->userA);

    // First save
    $this->postJson("/api/listings/{$this->listing1->id}/save");

    // Second save with action=save (idempotent call)
    $secondResponse = $this->postJson("/api/listings/{$this->listing1->id}/save", [
        'action' => 'save',
    ]);

    $secondResponse->assertOk()
        ->assertJson([
            'success' => true,
            'saved' => true,
        ]);

    expect(ListingSave::where('user_id', $this->userA->id)->where('listing_id', $this->listing1->id)->count())->toBe(1);
});

test('user can unsave a listing via delete or toggle action', function (): void {
    Passport::actingAs($this->userA);

    // Save first
    $this->postJson("/api/listings/{$this->listing1->id}/save");
    expect($this->listing1->fresh()->saves_count)->toBe(1);

    // Unsave via DELETE route
    $deleteResponse = $this->deleteJson("/api/listings/{$this->listing1->id}/save");
    $deleteResponse->assertOk()
        ->assertJson([
            'success' => true,
            'saved' => false,
        ]);

    $this->assertDatabaseMissing('listing_saves', [
        'user_id' => $this->userA->id,
        'listing_id' => $this->listing1->id,
    ]);

    expect($this->listing1->fresh()->saves_count)->toBe(0);
});

test('saved listings list returns only listings saved by the current user', function (): void {
    // User A saves listing 1
    Passport::actingAs($this->userA);
    $this->postJson("/api/listings/{$this->listing1->id}/save");

    // User B saves listing 2
    Passport::actingAs($this->userB);
    $this->postJson("/api/listings/{$this->listing2->id}/save");

    // Check User A saved list
    Passport::actingAs($this->userA);
    $responseA = $this->getJson('/api/listings/saved');
    $responseA->assertOk();

    $savedIdsA = collect($responseA->json('data'))->pluck('id')->all();
    expect($savedIdsA)->toContain($this->listing1->id);
    expect($savedIdsA)->not->toContain($this->listing2->id);

    // Check User B saved list
    Passport::actingAs($this->userB);
    $responseB = $this->getJson('/api/listings/saved');
    $responseB->assertOk();

    $savedIdsB = collect($responseB->json('data'))->pluck('id')->all();
    expect($savedIdsB)->toContain($this->listing2->id);
    expect($savedIdsB)->not->toContain($this->listing1->id);
});

test('saved listings list hides listings that become draft or inaccessible', function (): void {
    // User A saves listing 1
    Passport::actingAs($this->userA);
    $this->postJson("/api/listings/{$this->listing1->id}/save");

    // Listing 1 is turned into draft by owner
    $this->listing1->update(['status' => ListingStatus::Draft]);

    // Check User A saved list: listing 1 should be excluded from display
    $response = $this->getJson('/api/listings/saved');
    $response->assertOk();

    $savedIds = collect($response->json('data'))->pluck('id')->all();
    expect($savedIds)->not->toContain($this->listing1->id);
});

test('unauthenticated user cannot save or access saved listings', function (): void {
    $this->postJson("/api/listings/{$this->listing1->id}/save")->assertUnauthorized();
    $this->getJson('/api/listings/saved')->assertUnauthorized();
});
