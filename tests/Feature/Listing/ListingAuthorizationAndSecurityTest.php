<?php

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->otherUser = User::factory()->create([
        'badge' => 'none',
    ]);

    $this->agent = User::factory()->create([
        'badge' => 'agent',
    ]);

    $this->listing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Propriété Principale',
    ]);

    $this->draft = Listing::factory()->draft()->create([
        'owner_id' => $this->owner->id,
        'submission_key' => 'secret-submission-key-12345',
        'title' => 'Brouillon Privé',
    ]);
});

test('user cannot access or read another users draft', function (): void {
    Passport::actingAs($this->otherUser);

    // Attempting to read by submission key of another user
    $response = $this->getJson('/api/listings/form/draft?submission_key=secret-submission-key-12345');

    // Should return null data so existence of the other user's draft is not leaked
    $response->assertOk();
    expect($response->json('data'))->toBeNull();
});

test('user cannot update or overwrite another users draft via submission key', function (): void {
    Passport::actingAs($this->otherUser);

    // Attempting to hijack draft with secret submission key
    $response = $this->postJson('/api/listings/form/draft', [
        'submission_key' => 'secret-submission-key-12345',
        'step' => 2,
        'form_data' => [
            'title' => 'Piraté par un tiers',
        ],
    ]);

    // Should create their own separate draft rather than touching the owner's draft
    $this->assertDatabaseMissing('listings', [
        'id' => $this->draft->id,
        'title' => 'Piraté par un tiers',
    ]);
});

test('unauthorized user cannot update details of another users listing', function (): void {
    Passport::actingAs($this->otherUser);

    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        'title' => 'Nouveau Titre Non Autorisé',
    ]);

    $response->assertStatus(403);
    expect($this->listing->fresh()->title)->toBe('Propriété Principale');
});

test('unauthorized user cannot delete another users listing', function (): void {
    Passport::actingAs($this->otherUser);

    $response = $this->deleteJson("/api/listings/{$this->listing->id}");

    $response->assertStatus(403);
    $this->assertNotSoftDeleted($this->listing);
});

test('arbitrary agent cannot modify listing not assigned to them', function (): void {
    Passport::actingAs($this->agent);

    // Listing belongs to owner, not assigned to this agent
    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        'title' => 'Modification par un agent non mandaté',
    ]);

    $response->assertStatus(403);
});

test('assigned agent can modify listing details when delegated', function (): void {
    $this->listing->update(['agent_id' => $this->agent->id]);

    Passport::actingAs($this->agent);

    $response = $this->patchJson("/api/listings/{$this->listing->id}", [
        'title' => 'Titre mis à jour par l agent mandaté',
    ]);

    $response->assertOk();
    expect($this->listing->fresh()->title)->toBe('Titre mis à jour par l agent mandaté');
});

test('owner cannot set rejected status directly without moderation', function (): void {
    Passport::actingAs($this->owner);

    $response = $this->patchJson("/api/listings/{$this->listing->id}/status", [
        'status' => 'rejected',
    ]);

    // Rejection is reserved for admins/moderators
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('owner can transition their listing status to reserved or taken', function (): void {
    Passport::actingAs($this->owner);

    $response = $this->patchJson("/api/listings/{$this->listing->id}/status", [
        'status' => 'reserved',
    ]);

    $response->assertOk();
    expect($this->listing->fresh()->status)->toBe(ListingStatus::Reserved);
});

test('reels model can reference a listing via listing_id relation', function (): void {
    $reel = Reel::factory()->create([
        'user_id' => $this->owner->id,
        'listing_id' => $this->listing->id,
    ]);

    expect($reel->listing)->not->toBeNull();
    expect($reel->listing->id)->toBe($this->listing->id);
    expect($this->listing->reels->first()->id)->toBe($reel->id);
});
