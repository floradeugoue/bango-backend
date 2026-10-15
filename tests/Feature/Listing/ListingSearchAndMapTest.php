<?php

use App\Enums\Listing\ListingMode;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'badge' => 'landlord',
    ]);

    // Listing 1: Douala Akwa, Rent, 150000, 2 bedrooms, lat: 4.05, lng: 9.70
    $this->doualaListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Appartement spacieux Akwa Douala',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'mode' => ListingMode::Rent,
        'price' => 150000,
        'bedrooms' => 2,
        'lat' => 4.0505,
        'lng' => 9.7020,
    ]);

    // Listing 2: Douala Bonapriso, Rent, 350000, 3 bedrooms, lat: 4.03, lng: 9.69
    $this->bonaprisoListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Villa de standing Bonapriso',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'mode' => ListingMode::Rent,
        'price' => 350000,
        'bedrooms' => 3,
        'lat' => 4.0310,
        'lng' => 9.6930,
    ]);

    // Listing 3: Yaoundé Bastos, Sale, 85000000, 4 bedrooms, lat: 3.88, lng: 11.51
    $this->yaoundeListing = Listing::factory()->published()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Maison d architecte Bastos Yaounde',
        'city' => 'Yaoundé',
        'neighbourhood' => 'Bastos',
        'mode' => ListingMode::Sale,
        'price' => 85000000,
        'bedrooms' => 4,
        'lat' => 3.8840,
        'lng' => 11.5120,
    ]);

    // Listing 4: Draft in Douala Akwa - should NEVER appear in search or map
    $this->draftListing = Listing::factory()->draft()->create([
        'owner_id' => $this->owner->id,
        'title' => 'Chantier secret Akwa',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'lat' => 4.0506,
        'lng' => 9.7021,
    ]);
});

test('search filters listings by city', function (): void {
    $response = $this->getJson('/api/listings?city=Douala');

    $response->assertOk();
    $data = collect($response->json('data'));

    expect($data->pluck('id'))->toContain($this->doualaListing->id);
    expect($data->pluck('id'))->toContain($this->bonaprisoListing->id);
    expect($data->pluck('id'))->not->toContain($this->yaoundeListing->id);
    expect($data->pluck('id'))->not->toContain($this->draftListing->id);
});

test('search filters listings by neighbourhood', function (): void {
    $response = $this->getJson('/api/listings?neighbourhood=Bonapriso');

    $response->assertOk();
    $data = collect($response->json('data'));

    expect($data->count())->toBe(1);
    expect($data->first()['id'])->toBe($this->bonaprisoListing->id);
});

test('search filters listings by mode and price range', function (): void {
    $response = $this->getJson('/api/listings?mode=rent&min_price=100000&max_price=200000');

    $response->assertOk();
    $data = collect($response->json('data'));

    expect($data->count())->toBe(1);
    expect($data->first()['id'])->toBe($this->doualaListing->id);
});

test('search filters listings by bedrooms', function (): void {
    $response = $this->getJson('/api/listings?bedrooms=3');

    $response->assertOk();
    $data = collect($response->json('data'));

    expect($data->count())->toBe(1);
    expect($data->first()['id'])->toBe($this->bonaprisoListing->id);
});

test('text query q matches title, neighbourhood or city', function (): void {
    $response = $this->getJson('/api/listings?q=architecte');

    $response->assertOk();
    $data = collect($response->json('data'));

    expect($data->count())->toBe(1);
    expect($data->first()['id'])->toBe($this->yaoundeListing->id);
});

test('count endpoint is synchronized with feed search filters', function (): void {
    // 1. All published
    $allCount = $this->getJson('/api/listings/count');
    $allCount->assertOk()->assertJson(['success' => true, 'total' => 3]);

    // 2. City Douala
    $doualaCount = $this->getJson('/api/listings/count?city=Douala');
    $doualaCount->assertOk()->assertJson(['success' => true, 'total' => 2]);

    // 3. Sale mode
    $saleCount = $this->getJson('/api/listings/count?mode=sale');
    $saleCount->assertOk()->assertJson(['success' => true, 'total' => 1]);

    // 4. Zero results query
    $zeroCount = $this->getJson('/api/listings/count?city=Kribi');
    $zeroCount->assertOk()->assertJson(['success' => true, 'total' => 0]);
});

test('map endpoint returns listings within bounding box and excludes drafts', function (): void {
    // Bounding box covering Douala area (lat 4.00 to 4.10, lng 9.60 to 9.80)
    $response = $this->getJson('/api/listings/map?north=4.10&south=4.00&east=9.80&west=9.60');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'pins',
                'clusters',
                'total',
            ],
        ]);

    $pins = collect($response->json('data.pins'));
    $pinIds = $pins->pluck('id')->all();

    expect($pinIds)->toContain($this->doualaListing->id);
    expect($pinIds)->toContain($this->bonaprisoListing->id);
    expect($pinIds)->not->toContain($this->yaoundeListing->id); // Out of bounds
    expect($pinIds)->not->toContain($this->draftListing->id);   // Draft hidden
});

test('map endpoint validates bounding box coordinates', function (): void {
    // Invalid coordinate values (out of range latitude > 90)
    $response = $this->getJson('/api/listings/map?north=120&south=4.00&east=9.80&west=9.60');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['north']);
});
