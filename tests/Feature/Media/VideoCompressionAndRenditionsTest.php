<?php

use App\Enums\Reel\ReelStatus;
use App\Jobs\Reel\ProcessReelVideoJob;
use App\Models\Reel\Reel;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use App\Services\Media\MediaStorageService;
use App\Services\Reel\CloudflareStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
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

it('POST /media/upload transcode les vidéos en renditions et extrait le poster_url', function () {
    $user = User::factory()->create();
    app(LegalDocumentService::class)->recordAcceptance($user);
    Passport::actingAs($user);

    $fakeVideo = UploadedFile::fake()->create('dance.mp4', 8000, 'video/mp4');

    $response = postJson('/api/square-cr1/media/upload', [
        'file' => $fakeVideo,
        'kind' => 'video',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.type', 'video')
        ->assertJsonStructure([
            'data' => [
                'id',
                'url',
                'original_url',
                'poster_url',
                'posterUrl',
                'renditions' => ['480p', '720p', '1080p'],
            ],
        ]);

    $data = $response->json('data');

    expect($data['original_url'])->toContain('.mp4')
        ->and($data['url'])->toContain('720p.mp4')
        ->and($data['url'])->not->toBe($data['original_url']) // L'original est mis hors de la lecture
        ->and($data['renditions']['480p'])->toContain('480p.mp4')
        ->and($data['renditions']['720p'])->toContain('720p.mp4')
        ->and($data['renditions']['1080p'])->toContain('1080p.mp4')
        ->and($data['poster_url'])->not->toBeNull()
        ->and($data['poster_url'])->toContain('poster.jpg');
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
        ->and($result['url'])->not->toBe($result['original_url'])
        ->and($result['poster_url'])->toContain('poster.jpg');
});
