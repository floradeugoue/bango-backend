<?php

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    $this->publishedListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Superbe Appartement Bonanjo',
        'price' => 250000,
        'deposit_months' => 2,
        'advance_months' => 1,
        'views_count' => 10,
    ]);

    $this->draftListing = Listing::factory()->draft()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Brouillon Secret',
    ]);

    $this->reviewListing = Listing::factory()->create([
        'owner_id' => $this->owner->id,
        'status' => ListingStatus::Review,
        'title' => 'Annonce en Modération',
    ]);
});

test('public feed returns only published listings and excludes drafts and review listings', function (): void {
    $response = $this->getJson('/api/listings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'mode',
                    'kind',
                    'city',
                    'neighbourhood',
                    'price',
                    'currency',
                    'photos',
                    'owner',
                ],
            ],
            'meta' => [
                'current_page',
                'per_page',
                'total',
            ],
        ]);

    $titles = collect($response->json('data'))->pluck('title')->all();

    expect($titles)->toContain('Superbe Appartement Bonanjo');
    expect($titles)->not->toContain('Brouillon Secret');
    expect($titles)->not->toContain('Annonce en Modération');
});

test('feed includes upfront move in costs in detail endpoint for rental listings', function (): void {
    $response = $this->getJson("/api/listings/{$this->publishedListing->id}");

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->not->toBeNull();
    expect($data['move_in_costs'])->toBeArray();
    expect($data['upfront_total'])->toBe(760000); // 250k*2 + 250k*1 + 10k visit
    expect($data['monthly_total'])->toBe(265000); // 250k rent + 15k charges
});

test('consulting listing detail returns complete breakdown and increments views count', function (): void {
    $initialViews = $this->publishedListing->views_count;

    $response = $this->getJson("/api/listings/{$this->publishedListing->id}");

    $response->assertOk()
        ->assertJson([
            'data' => [
                'id' => $this->publishedListing->id,
                'title' => 'Superbe Appartement Bonanjo',
                'status' => 'published',
            ],
        ]);

    expect($response->json('data.upfront_total'))->toBe(760000);

    // Refresh model to check views_count incremented
    expect($this->publishedListing->fresh()->views_count)->toBe($initialViews + 1);
});

test('listing detail includes sorted media and owner public information', function (): void {
    ListingMedia::factory()->create([
        'listing_id' => $this->publishedListing->id,
        'type' => 'image',
        'order' => 2,
    ]);

    ListingMedia::factory()->create([
        'listing_id' => $this->publishedListing->id,
        'type' => 'image',
        'order' => 1,
    ]);

    $response = $this->getJson("/api/listings/{$this->publishedListing->id}");

    $response->assertOk();
    $media = $response->json('data.all_media');
    expect(count($media))->toBe(2);
    expect($media[0]['order'])->toBe(1);
    expect($media[1]['order'])->toBe(2);
    expect($response->json('data.owner.id'))->toBe($this->owner->id);
});

test('unauthenticated user or third party cannot view draft or review listing', function (): void {
    $thirdParty = User::factory()->create();

    // As guest
    $guestResponse = $this->getJson("/api/listings/{$this->draftListing->id}");
    $guestResponse->assertNotFound();

    // As third party
    $thirdPartyResponse = $this->actingAs($thirdParty)->getJson("/api/listings/{$this->draftListing->id}");
    $thirdPartyResponse->assertNotFound();

    // Review listing also protected from public
    $reviewResponse = $this->actingAs($thirdParty)->getJson("/api/listings/{$this->reviewListing->id}");
    $reviewResponse->assertNotFound();
});

test('owner can view their own draft and review listings with full detail', function (): void {
    $response = $this->actingAs($this->owner)->getJson("/api/listings/{$this->draftListing->id}");

    $response->assertOk()
        ->assertJson([
            'data' => [
                'id' => $this->draftListing->id,
                'title' => 'Brouillon Secret',
                'status' => 'draft',
            ],
        ]);
});

test('showing non-existent listing returns 404', function (): void {
    $randomUuid = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';
    $response = $this->getJson("/api/listings/{$randomUuid}");

    $response->assertNotFound();
});
