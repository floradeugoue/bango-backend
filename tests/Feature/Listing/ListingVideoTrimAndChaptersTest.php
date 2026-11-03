<?php

use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->landlord = User::factory()->create([
        'badge' => 'landlord',
        'is_verified' => true,
    ]);
    Storage::fake('public');
});

test('création d\'une annonce avec video_trim, video_cover_ms et video_chapters valides', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Bel appartement avec visite guidée découpage et repères',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 250000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 65,
        'city' => 'Dakar',
        'neighbourhood' => 'Mermoz',
        'video_trim' => [
            'start_ms' => 2000,
            'end_ms' => 35000, // 33s > 30s
        ],
        'video_cover_ms' => 8500,
        'video_chapters' => [
            ['room' => 'entry', 'at_ms' => 2500],
            ['room' => 'living', 'at_ms' => 10000],
            ['room' => 'kitchen', 'at_ms' => 20000],
            ['room' => 'shower', 'at_ms' => 28000],
        ],
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.video_trim.start_ms', 2000)
        ->assertJsonPath('data.video_trim.end_ms', 35000)
        ->assertJsonPath('data.video_cover_ms', 8500)
        ->assertJsonCount(4, 'data.video_chapters')
        ->assertJsonPath('data.video_chapters.0.room', 'entry')
        ->assertJsonPath('data.video_chapters.1.room', 'living');

    $listing = Listing::first();
    expect($listing->video_metrics['video_trim']['start_ms'])->toBe(2000)
        ->and($listing->video_metrics['video_cover_ms'])->toBe(8500)
        ->and($listing->video_metrics['video_chapters'])->toHaveCount(4);
});

test('rejet de la création si le découpage video_trim fait moins de 30 secondes', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Annonce avec trim trop court',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 250000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 65,
        'city' => 'Dakar',
        'neighbourhood' => 'Mermoz',
        'video_trim' => [
            'start_ms' => 5000,
            'end_ms' => 25000, // 20s < 30s
        ],
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['video_trim']);
});

test('rejet si une pièce de video_chapters n\'est pas dans la liste autorisée', function () {
    Passport::actingAs($this->landlord);

    $payload = [
        'title' => 'Annonce avec pièce inconnue',
        'mode' => 'rent',
        'kind' => 'flat',
        'price' => 250000,
        'bedrooms' => 2,
        'showers' => 1,
        'area' => 65,
        'city' => 'Dakar',
        'neighbourhood' => 'Mermoz',
        'video_chapters' => [
            ['room' => 'attic', 'at_ms' => 1200], // invalid room
        ],
    ];

    $response = $this->postJson('/api/listings', $payload);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['video_chapters.0.room']);
});

test('mise à jour des métadonnées vidéo (video_trim, video_cover_ms, video_chapters) sur une annonce existante', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'city' => 'Dakar',
    ]);

    $media = ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => 'video',
        'path' => 'videos/tour.mp4',
        'url' => 'http://localhost/storage/videos/tour.mp4',
        'thumbnail_url' => 'http://localhost/storage/videos/tour_poster.jpg',
        'duration' => 60,
    ]);

    $updatePayload = [
        'video_trim' => [
            'start_ms' => 1000,
            'end_ms' => 45000, // 44s
        ],
        'video_cover_ms' => 12000,
        'video_chapters' => [
            ['room' => 'outside', 'at_ms' => 1500],
            ['room' => 'living', 'at_ms' => 12000],
            ['room' => 'kitchen', 'at_ms' => 25000],
        ],
    ];

    $response = $this->putJson("/api/listings/{$listing->id}", $updatePayload);

    $response->assertStatus(200)
        ->assertJsonPath('data.video_trim.start_ms', 1000)
        ->assertJsonPath('data.video_cover_ms', 12000)
        ->assertJsonPath('data.video_poster_url', 'http://localhost/storage/videos/tour_poster.jpg')
        ->assertJsonCount(3, 'data.video_chapters');

    // Vérifier que le ListingMedia a aussi été synchronisé
    $media->refresh();
    expect($media->metadata['video_trim']['start_ms'])->toBe(1000)
        ->and($media->metadata['video_cover_ms'])->toBe(12000)
        ->and($media->metadata['video_chapters'])->toHaveCount(3);
});

test('téléversement direct d\'une vidéo avec video_trim, video_cover_ms et video_chapters dans ListingMediaController', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);
    $video = UploadedFile::fake()->create('tour.mp4', 1024 * 20, 'video/mp4');

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $video,
        'type' => 'video',
        'duration' => 60,
        'width' => 1080,
        'height' => 1920,
        'video_trim' => [
            'start_ms' => 5000,
            'end_ms' => 40000,
        ],
        'video_cover_ms' => 15000,
        'video_chapters' => [
            ['room' => 'entry', 'at_ms' => 5000],
            ['room' => 'living', 'at_ms' => 15000],
            ['room' => 'kitchen', 'at_ms' => 28000],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'video')
        ->assertJsonPath('data.video_trim.start_ms', 5000)
        ->assertJsonPath('data.video_cover_ms', 15000)
        ->assertJsonCount(3, 'data.video_chapters')
        ->assertJsonPath('data.video_chapters.0.room', 'entry');
});

test('consultation publique de l\'annonce renvoie video_chapters, video_trim et video_poster_url pour les prospects', function () {
    $listing = Listing::factory()->create([
        'owner_id' => $this->landlord->id,
        'status' => 'published',
        'video_metrics' => [
            'video_trim' => ['start_ms' => 0, 'end_ms' => 45000],
            'video_cover_ms' => 10000,
            'video_poster_url' => 'https://example.com/cover.jpg',
            'video_chapters' => [
                ['room' => 'entry', 'at_ms' => 0],
                ['room' => 'living', 'at_ms' => 12000],
                ['room' => 'kitchen', 'at_ms' => 25000],
            ],
        ],
    ]);

    $media = ListingMedia::create([
        'listing_id' => $listing->id,
        'type' => 'video',
        'path' => 'videos/tour.mp4',
        'url' => 'https://example.com/tour.mp4',
        'thumbnail_url' => 'https://example.com/cover.jpg',
        'duration' => 45,
        'metadata' => [
            'video_trim' => ['start_ms' => 0, 'end_ms' => 45000],
            'video_cover_ms' => 10000,
            'video_poster_url' => 'https://example.com/cover.jpg',
            'video_chapters' => [
                ['room' => 'entry', 'at_ms' => 0],
                ['room' => 'living', 'at_ms' => 12000],
                ['room' => 'kitchen', 'at_ms' => 25000],
            ],
        ],
    ]);

    // Visiteur non authentifié (prospect)
    $response = $this->getJson("/api/listings/{$listing->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.tour_url', 'https://example.com/tour.mp4')
        ->assertJsonPath('data.video_poster_url', 'https://example.com/cover.jpg')
        ->assertJsonPath('data.video_trim.end_ms', 45000)
        ->assertJsonPath('data.video_cover_ms', 10000)
        ->assertJsonCount(3, 'data.video_chapters')
        ->assertJsonPath('data.video_chapters.0.room', 'entry')
        ->assertJsonPath('data.video_chapters.1.room', 'living')
        ->assertJsonPath('data.video_chapters.2.room', 'kitchen');
});
