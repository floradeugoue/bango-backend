<?php

use App\Enums\Listing\ListingPhotoRoom;
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

test('un propriétaire peut téléverser une photo valide sur son annonce avec une pièce qualifiée', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);
    $photo = UploadedFile::fake()->image('salon.jpg', 1280, 720)->size(1500);

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $photo,
        'type' => 'image',
        'room' => 'living',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'image')
        ->assertJsonPath('data.room', ListingPhotoRoom::Living->value)
        ->assertJsonPath('data.order', 1);

    $this->assertDatabaseHas('listing_media', [
        'listing_id' => $listing->id,
        'type' => 'image',
        'room' => ListingPhotoRoom::Living->value,
    ]);
});

test('un propriétaire peut téléverser une vidéo valide de visite guidée en portrait', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);
    $video = UploadedFile::fake()->create('tour.mp4', 1024 * 20, 'video/mp4');

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $video,
        'type' => 'video',
        'duration' => 60, // 60 secondes (entre 30s et 180s)
        'width' => 1080,
        'height' => 1920, // vertical portrait
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.type', 'video')
        ->assertJsonPath('data.duration', 60);

    $this->assertDatabaseHas('listing_media', [
        'listing_id' => $listing->id,
        'type' => 'video',
        'duration' => 60,
    ]);
});

test('le téléversement vidéo refuse les durées trop courtes, trop longues ou non verticales', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);

    // 1. Trop courte (< 30 secondes)
    $videoShort = UploadedFile::fake()->create('short.mp4', 5000, 'video/mp4');
    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $videoShort,
        'type' => 'video',
        'duration' => 15,
        'width' => 1080,
        'height' => 1920,
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['file']);

    // 2. Trop longue (> 180 secondes)
    $videoLong = UploadedFile::fake()->create('long.mp4', 25000, 'video/mp4');
    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $videoLong,
        'type' => 'video',
        'duration' => 240,
        'width' => 1080,
        'height' => 1920,
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['file']);

    // 3. Vidéo horizontale (hauteur <= largeur)
    $videoHorizontal = UploadedFile::fake()->create('horizontal.mp4', 15000, 'video/mp4');
    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $videoHorizontal,
        'type' => 'video',
        'duration' => 60,
        'width' => 1920,
        'height' => 1080,
    ]);
    $response->assertStatus(422)->assertJsonValidationErrors(['file']);
});

test('le téléversement refuse les formats MIME non autorisés', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);
    $scriptFile = UploadedFile::fake()->create('exploit.exe', 100, 'application/x-msdownload');

    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $scriptFile,
        'type' => 'image',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['file']);
});

test('un utilisateur peut réordonner les médias d\'une annonce', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);
    $media1 = ListingMedia::factory()->create(['listing_id' => $listing->id, 'order' => 0]);
    $media2 = ListingMedia::factory()->create(['listing_id' => $listing->id, 'order' => 1]);
    $media3 = ListingMedia::factory()->create(['listing_id' => $listing->id, 'order' => 2]);

    $response = $this->patchJson("/api/listings/{$listing->id}/media/order", [
        'media_ids' => [$media3->id, $media1->id, $media2->id],
    ]);

    $response->assertStatus(200);

    expect($media3->fresh()->order)->toBe(0)
        ->and($media1->fresh()->order)->toBe(1)
        ->and($media2->fresh()->order)->toBe(2);
});

test('un utilisateur peut supprimer un média et le fichier est purgé', function () {
    Passport::actingAs($this->landlord);

    $listing = Listing::factory()->create(['owner_id' => $this->landlord->id]);
    $file = UploadedFile::fake()->image('delete_me.jpg');
    $path = $file->store('listings/images/1', 'public');

    $media = ListingMedia::factory()->create([
        'listing_id' => $listing->id,
        'path' => $path,
    ]);

    Storage::disk('public')->assertExists($path);

    $response = $this->deleteJson("/api/listings/{$listing->id}/media/{$media->id}");
    $response->assertStatus(200);

    $this->assertDatabaseMissing('listing_media', ['id' => $media->id]);
    Storage::disk('public')->assertMissing($path);
});

test('un utilisateur tiers ne peut pas modifier ni supprimer les médias d\'une annonce qui ne lui appartient pas', function () {
    $otherUser = User::factory()->create(['badge' => 'landlord']);
    $listing = Listing::factory()->create(['owner_id' => $otherUser->id]);
    $media = ListingMedia::factory()->create(['listing_id' => $listing->id]);

    Passport::actingAs($this->landlord);

    $photo = UploadedFile::fake()->image('hacker.jpg');
    $response = $this->postJson("/api/listings/{$listing->id}/media", [
        'file' => $photo,
        'type' => 'image',
    ]);
    $response->assertStatus(403);

    $deleteResponse = $this->deleteJson("/api/listings/{$listing->id}/media/{$media->id}");
    $deleteResponse->assertStatus(403);
});
