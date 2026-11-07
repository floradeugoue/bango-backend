<?php

use App\Enums\Media\MediaProcessingStatus;
use App\Enums\Reel\ReelStatus;
use App\Jobs\Reel\ProcessReelVideoJob;
use App\Jobs\Square\ProcessSquareVideoJob;
use App\Models\Reel\Reel;
use App\Models\Square\PostMedia;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use App\Services\Media\MediaStorageService;
use App\Services\Media\VideoTranscodingService;
use App\Services\Reel\CloudflareStreamService;
use App\Services\Square\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('public');
});

it('ProcessReelVideoJob transcode les renditions 480p, 720p, 1080p et garde l\'original hors de la lecture', function () {
    $user = User::factory()->create();

    // Vidéo source d'origine
    Storage::disk('public')->put('reels/videos/original_heavy_4k.mp4', 'dummy 4K raw content');
    $originalUrl = Storage::disk('public')->url('reels/videos/original_heavy_4k.mp4');

    $reel = Reel::factory()->create([
        'user_id' => $user->id,
        'status' => ReelStatus::Processing,
        'video_url' => $originalUrl,
        'original_video_url' => null,
        'thumbnail_url' => null,
    ]);

    $cloudflareMock = mock(CloudflareStreamService::class);
    $cloudflareMock->shouldReceive('isConfigured')->andReturn(false);

    $job = new ProcessReelVideoJob($reel);
    $job->handle($cloudflareMock);

    $reel->refresh();

    expect($reel->status)->toBe(ReelStatus::Ready)
        ->and($reel->original_video_url)->toBe($originalUrl) // L'original est conservé
        ->and($reel->video_url)->not->toBe($originalUrl) // La lecture ne sert plus l'original brut
        ->and($reel->video_url)->toContain('720p.mp4')
        ->and($reel->renditions)->toHaveKeys(['480p', '720p', '1080p'])
        ->and($reel->renditions['480p'])->toContain('480p.mp4')
        ->and($reel->renditions['720p'])->toContain('720p.mp4')
        ->and($reel->renditions['1080p'])->toContain('1080p.mp4')
        ->and($reel->thumbnail_url)->not->toBeNull()
        ->and($reel->thumbnail_url)->toContain('poster.jpg');

    // Vérifier que les fichiers de chaque profil existent au stockage
    expect(Storage::disk('public')->exists(parse_url($reel->renditions['480p'], PHP_URL_PATH) ? ltrim(str_replace('/storage/', '', parse_url($reel->renditions['480p'], PHP_URL_PATH)), '/') : ''))->toBeTrue();
    expect(Storage::disk('public')->exists(parse_url($reel->renditions['720p'], PHP_URL_PATH) ? ltrim(str_replace('/storage/', '', parse_url($reel->renditions['720p'], PHP_URL_PATH)), '/') : ''))->toBeTrue();
    expect(Storage::disk('public')->exists(parse_url($reel->renditions['1080p'], PHP_URL_PATH) ? ltrim(str_replace('/storage/', '', parse_url($reel->renditions['1080p'], PHP_URL_PATH)), '/') : ''))->toBeTrue();
});

it('POST /media/upload enregistre immédiatement le média sans bloquer sur FFmpeg puis ProcessSquareVideoJob génère les renditions', function () {
    $user = User::factory()->create();
    app(LegalDocumentService::class)->recordAcceptance($user);
    Passport::actingAs($user);

    $fakeVideo = UploadedFile::fake()->create('dance.mp4', 8000, 'video/mp4');

    // 1. Upload rapide non bloquant
    $response = postJson('/api/square-cr1/media/upload', [
        'file' => $fakeVideo,
        'kind' => 'video',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.type', 'video')
        ->assertJsonPath('data.status', 'pending');

    $uploaded = $response->json('data');
    expect($uploaded['url'])->not->toBeNull()
        ->and($uploaded['original_url'])->not->toBeNull();

    // 2. Publication du post et transcodage asynchrone des renditions
    $postResponse = postJson('/api/square-cr1/posts', [
        'body' => 'Vidéo avec transcodage adaptatif',
        'media' => [
            [
                'url' => $uploaded['url'],
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'duration' => 20,
            ],
        ],
    ]);

    $postResponse->assertStatus(201);
    $mediaId = $postResponse->json('data.media_items.0.id');
    $postMedia = PostMedia::find($mediaId);

    // Traitement du Job
    $job = new ProcessSquareVideoJob($postMedia);
    $job->handle(app(VideoProcessingService::class), app(VideoTranscodingService::class));

    $postMedia->refresh();

    expect($postMedia->status)->toBe(MediaProcessingStatus::Completed)
        ->and($postMedia->media_url)->toContain('720p.mp4')
        ->and($postMedia->poster_url)->not->toBeNull()
        ->and($postMedia->poster_url)->toContain('poster.jpg')
        ->and($postMedia->edit_metadata['renditions'])->toHaveKeys(['480p', '720p', '1080p']);
});

it('storeListingVideo génère les renditions adaptatives et l\'image d\'aperçu', function () {
    $user = User::factory()->create();

    $fakeVideo = UploadedFile::fake()->create('apartment_tour.mp4', 12000, 'video/mp4');

    /** @var MediaStorageService $storageService */
    $storageService = app(MediaStorageService::class);
    $result = $storageService->storeListingVideo($fakeVideo, $user, null, 45, 1080, 1920);

    expect($result)->toHaveKeys([
        'url',
        'original_url',
        'poster_url',
        'posterUrl',
        'thumbnail_url',
        'renditions',
    ])
        ->and($result['renditions'])->toHaveKeys(['480p', '720p', '1080p'])
        ->and($result['url'])->toContain('720p.mp4')
        ->and($result['url'])->not->toBe($result['original_url']);

    expect($result['poster_url'])->toContain('poster.jpg');
});

it('VideoTranscodingService ne sort jamais une version plus lourde que l\'original et n\'agrandit pas au-delà de la source', function () {
    $service = new VideoTranscodingService;

    // Création d'une petite vidéo originale de 300 octets
    $originalData = str_repeat('A', 300);
    Storage::disk('public')->put('videos/small_source.mp4', $originalData);
    $originalUrl = Storage::disk('public')->url('videos/small_source.mp4');

    $result = $service->transcode($originalUrl, 'videos/renditions', 1, 'public');

    expect($result['renditions'])->toHaveKeys(['480p', '720p', '1080p']);

    $disk = Storage::disk('public');
    foreach (['480p', '720p', '1080p'] as $profile) {
        $relPath = $result['relative_renditions'][$profile];
        expect($disk->exists($relPath))->toBeTrue();
        // La taille d'aucune rendition ne doit dépasser la taille originale
        expect(filesize($disk->path($relPath)))->toBeLessThanOrEqual(strlen($originalData));
    }
});

it('VideoTranscodingService remplace par l\'original si un profil FFmpeg sort plus lourd que l\'original', function () {
    $service = new VideoTranscodingService;

    $originalData = str_repeat('X', 500);
    Storage::disk('public')->put('videos/test_heavy.mp4', $originalData);
    $disk = Storage::disk('public');
    $originalUrl = $disk->url('videos/test_heavy.mp4');

    // On force la présence de FFmpeg pour tester le filtre et le remplacement
    $service->setFfmpegAvailable(true);

    // Process::fake simule un FFmpeg qui produit un fichier de 5000 octets (plus lourd que les 500 octets de l'original)
    Process::fake([
        'ffprobe *' => Process::result("540x960\n"),
        'ffmpeg *' => function (PendingProcess $process) {
            // Si c'est l'extraction de poster
            if (str_contains($process->command, '-vframes 1')) {
                return Process::result();
            }

            // Récupérer le fichier de destination MP4 à la fin de la commande (même avec espaces dans le chemin)
            if (preg_match('/[\'"]([^\'"]+\.mp4)[\'"]\s*$/', $process->command, $matches)) {
                $targetFile = $matches[1];
                @file_put_contents($targetFile, str_repeat('HEAVY_DATA_', 400)); // ~4400 octets
            }

            return Process::result();
        },
    ]);

    $result = $service->transcode($originalUrl, 'videos/renditions_guard', 2, 'public');

    foreach (['480p', '720p', '1080p'] as $profile) {
        $relPath = $result['relative_renditions'][$profile];
        expect($disk->exists($relPath))->toBeTrue();
        $fileSize = filesize($disk->path($relPath));
        // Doit avoir été ramené à la taille de l'original (500 octets) et non 4400 octets
        expect($fileSize)->toBeLessThanOrEqual(500);
    }
});
