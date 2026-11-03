<?php

use App\Models\Reel\Sound;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('public');
});

it('découpe une vidéo Square selon trim, calcule la vraie durée et retourne les métadonnées', function () {
    $user = User::factory()->create([
        'handle' => 'video_creator',
        'display_name' => 'Créateur Vidéo',
    ]);
    app(LegalDocumentService::class)->recordAcceptance($user);

    Passport::actingAs($user);

    // Fichier vidéo simulé sur le disque public
    Storage::disk('public')->put('square/videos/sample.mp4', 'dummy video content');

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Regardez ma nouvelle vidéo découpée !',
        'media' => [
            [
                'url' => '/storage/square/videos/sample.mp4',
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'file_size' => 1048576,
                'duration' => 30, // durée brute initiale 30s
                'edit_metadata' => [
                    'trim' => [
                        'start_ms' => 2000,
                        'end_ms' => 12000, // Découpage de 10s
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.media_items.0.type', 'video')
        ->assertJsonPath('data.media_items.0.duration', 10) // La vraie durée = (12000 - 2000) / 1000 = 10s
        ->assertJsonPath('data.media_items.0.edit_metadata.trim.start_ms', 2000)
        ->assertJsonPath('data.media_items.0.edit_metadata.trim.end_ms', 12000);

    $this->assertDatabaseHas('post_media', [
        'media_type' => 'video',
        'duration' => 10,
    ]);
});

it('génère et renvoie le poster_url pour une vidéo avec cover_ms', function () {
    $user = User::factory()->create([
        'handle' => 'cover_chooser',
    ]);
    app(LegalDocumentService::class)->recordAcceptance($user);

    Passport::actingAs($user);

    Storage::disk('public')->put('square/videos/movie.mp4', 'dummy video');

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Vidéo avec choix de couverture à 3 secondes',
        'media' => [
            [
                'url' => '/storage/square/videos/movie.mp4',
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'duration' => 15,
                'edit_metadata' => [
                    'cover_ms' => 3000,
                ],
            ],
        ],
    ]);

    $response->assertStatus(201);

    $posterUrl = $response->json('data.media_items.0.poster_url');
    $posterUrlCamel = $response->json('data.media_items.0.posterUrl');
    $rootPosterUrl = $response->json('data.poster_url');

    expect($posterUrl)->not->toBeNull()
        ->and($posterUrl)->toContain('/storage/square/covers/')
        ->and($posterUrlCamel)->toBe($posterUrl)
        ->and($rootPosterUrl)->toBe($posterUrl);

    $this->assertDatabaseHas('post_media', [
        'media_type' => 'video',
    ]);
});

it('associe et renvoie les informations complètes du son pour lecture synchronisée par l\'application', function () {
    $user = User::factory()->create([
        'handle' => 'music_lover',
    ]);
    app(LegalDocumentService::class)->recordAcceptance($user);

    // Création d'un son dans le catalogue
    $sound = Sound::create([
        'id' => 'sound-afro-beat-1',
        'title' => 'Afro Vibes Douala',
        'artist' => 'Makossa All Stars',
        'genre' => 'Afrobeats',
        'audio_url' => 'sounds/catalogue/afro_vibes.mp3',
        'duration_seconds' => 45,
        'provenance' => 'licensed',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    Passport::actingAs($user);

    Storage::disk('public')->put('square/videos/dance.mp4', 'dummy video');

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Danse au rythme de Makossa !',
        'media' => [
            [
                'url' => '/storage/square/videos/dance.mp4',
                'type' => 'video',
                'duration' => 20,
                'edit_metadata' => [
                    'sound' => [
                        'id' => 'sound-afro-beat-1',
                        'start_ms' => 1500,
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.media_items.0.sound.id', 'sound-afro-beat-1')
        ->assertJsonPath('data.media_items.0.sound.title', 'Afro Vibes Douala')
        ->assertJsonPath('data.media_items.0.sound.artist', 'Makossa All Stars')
        ->assertJsonPath('data.media_items.0.sound.start_ms', 1500);

    $audioUrl = $response->json('data.media_items.0.sound.audio_url');
    expect($audioUrl)->toContain('sounds/catalogue/afro_vibes.mp3');
});

it('supporte les clés camelCase pour trim, coverMs et soundId', function () {
    $user = User::factory()->create([
        'handle' => 'camel_user',
    ]);
    app(LegalDocumentService::class)->recordAcceptance($user);

    $sound = Sound::create([
        'id' => 'sound-camel-2',
        'title' => 'Camel Chill',
        'artist' => 'Artist Two',
        'genre' => 'Chill',
        'audio_url' => 'sounds/catalogue/camel.mp3',
        'duration_seconds' => 60,
        'provenance' => 'licensed',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    Passport::actingAs($user);

    Storage::disk('public')->put('square/videos/camel.mp4', 'dummy video');

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Publication test format camelCase',
        'media' => [
            [
                'url' => '/storage/square/videos/camel.mp4',
                'type' => 'video',
                'duration' => 50,
                'edit_metadata' => [
                    'trim' => [
                        'startMs' => 5000,
                        'endMs' => 12000, // 7 secondes
                    ],
                    'coverMs' => 6000,
                    'sound' => [
                        'soundId' => 'sound-camel-2',
                        'startMs' => 2000,
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.media_items.0.duration', 7)
        ->assertJsonPath('data.media_items.0.sound.id', 'sound-camel-2')
        ->assertJsonPath('data.media_items.0.sound.start_ms', 2000);

    $posterUrl = $response->json('data.media_items.0.poster_url');
    expect($posterUrl)->not->toBeNull();
});

it('refuse une requête où trim.end_ms est inférieur ou égal à trim.start_ms', function () {
    $user = User::factory()->create([
        'handle' => 'invalid_user',
    ]);
    app(LegalDocumentService::class)->recordAcceptance($user);

    Passport::actingAs($user);

    Storage::disk('public')->put('square/videos/invalid.mp4', 'dummy video');

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Test trim invalide',
        'media' => [
            [
                'url' => '/storage/square/videos/invalid.mp4',
                'type' => 'video',
                'edit_metadata' => [
                    'trim' => [
                        'start_ms' => 5000,
                        'end_ms' => 3000, // Invalide : end_ms < start_ms
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['media.0.edit_metadata.trim']);
});
