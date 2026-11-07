<?php

use App\Enums\Media\MediaProcessingStatus;
use App\Jobs\Square\ProcessSquareVideoJob;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\User;
use App\Services\Legal\LegalDocumentService;
use App\Services\Media\VideoTranscodingService;
use App\Services\Square\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Storage::fake('public');
});

it('téléverse une vidéo valide et répond immédiatement avec status pending sans bloquer sur FFmpeg', function () {
    $user = User::factory()->create();
    app(LegalDocumentService::class)->recordAcceptance($user);
    Passport::actingAs($user);

    $fakeVideo = UploadedFile::fake()->create('dance.mp4', 5000, 'video/mp4');

    $response = postJson('/api/square-cr1/media/upload', [
        'file' => $fakeVideo,
        'kind' => 'video',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.type', 'video')
        ->assertJsonPath('data.status', 'pending');

    $data = $response->json('data');
    expect($data['url'])->not->toBeNull()
        ->and($data['url'])->toContain('square/videos')
        ->and($data['url'])->toContain('.mp4');
});

it('publie un post avec vidéo et déclenche le Job asynchrone ProcessSquareVideoJob', function () {
    Queue::fake();

    $user = User::factory()->create();
    app(LegalDocumentService::class)->recordAcceptance($user);
    Passport::actingAs($user);

    Storage::disk('public')->put('square/videos/sample.mp4', 'dummy video content');

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Superbe vidéo dans le Square',
        'media' => [
            [
                'url' => '/storage/square/videos/sample.mp4',
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'file_size' => 1048576,
                'duration' => 25,
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.media_items.0.type', 'video')
        ->assertJsonPath('data.media_items.0.status', 'pending');

    // Vérifie que le Job asynchrone a bien été dispatché
    Queue::assertPushed(ProcessSquareVideoJob::class, function ($job) {
        return $job->postMedia->duration === 25;
    });

    $this->assertDatabaseHas('post_media', [
        'media_type' => 'video',
        'status' => 'pending',
    ]);
});

it('exécute ProcessSquareVideoJob et fait passer le statut de pending à completed avec renditions', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    Storage::disk('public')->put('square/videos/my_video.mp4', 'dummy mp4 video stream content');
    $videoUrl = Storage::disk('public')->url('square/videos/my_video.mp4');

    $media = PostMedia::create([
        'post_id' => $post->id,
        'media_url' => $videoUrl,
        'media_type' => 'video',
        'mime_type' => 'video/mp4',
        'duration' => 20,
        'status' => MediaProcessingStatus::Pending,
    ]);

    expect($media->isPending())->toBeTrue();

    // Exécution du Job
    $job = new ProcessSquareVideoJob($media);
    $job->handle(app(VideoProcessingService::class), app(VideoTranscodingService::class));

    $media->refresh();

    expect($media->status)->toBe(MediaProcessingStatus::Completed)
        ->and($media->isCompleted())->toBeTrue()
        ->and($media->processed_at)->not->toBeNull()
        ->and($media->error_message)->toBeNull()
        ->and($media->poster_url)->not->toBeNull()
        ->and($media->edit_metadata['renditions'])->toHaveKeys(['480p', '720p', '1080p']);
});

it('gère l\'échec FFmpeg et passe le statut en failed sans bloquer indéfiniment en processing', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    Storage::disk('public')->put('square/videos/broken.mp4', 'corrupt content');
    $videoUrl = Storage::disk('public')->url('square/videos/broken.mp4');

    $media = PostMedia::create([
        'post_id' => $post->id,
        'media_url' => $videoUrl,
        'media_type' => 'video',
        'status' => MediaProcessingStatus::Pending,
    ]);

    $transcoderMock = mock(VideoTranscodingService::class);
    $transcoderMock->shouldReceive('transcode')
        ->andThrow(new RuntimeException('FFmpeg encoding failed: invalid input data.'));

    $job = new ProcessSquareVideoJob($media);

    // Simule l'appel de failed() lors de l'abandon de queue
    $job->failed(new RuntimeException('FFmpeg encoding failed: invalid input data.'));

    $media->refresh();

    expect($media->status)->toBe(MediaProcessingStatus::Failed)
        ->and($media->isFailed())->toBeTrue()
        ->and($media->error_message)->toContain('FFmpeg encoding failed');
});

it('permet de relancer un traitement échoué via la route retry et la commande artisan', function () {
    Queue::fake();

    $user = User::factory()->create();
    app(LegalDocumentService::class)->recordAcceptance($user);
    Passport::actingAs($user);

    $post = Post::factory()->create(['user_id' => $user->id]);

    $media = PostMedia::create([
        'post_id' => $post->id,
        'media_url' => 'http://localhost/storage/square/videos/fail.mp4',
        'media_type' => 'video',
        'status' => MediaProcessingStatus::Failed,
        'error_message' => 'Erreur précédente',
    ]);

    // 1. Relance via l'API
    $response = postJson("/api/square-cr1/posts/media/{$media->id}/retry");
    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(ProcessSquareVideoJob::class);

    $media->refresh();
    expect($media->status)->toBe(MediaProcessingStatus::Pending)
        ->and($media->error_message)->toBeNull();

    // 2. Relance via la commande Artisan
    $media->update(['status' => MediaProcessingStatus::Failed, 'error_message' => 'Erreur 2']);
    Artisan::call('media:retry-failed', ['--id' => $media->id]);

    $media->refresh();
    expect($media->status)->toBe(MediaProcessingStatus::Pending);
});

it('empêche plusieurs traitements simultanés de la même vidéo via le verrou atomique', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $media = PostMedia::create([
        'post_id' => $post->id,
        'media_url' => 'http://localhost/storage/square/videos/sample.mp4',
        'media_type' => 'video',
        'status' => MediaProcessingStatus::Pending,
    ]);

    // Poser un verrou préalable pour simuler un traitement concurrent déjà en cours
    $lockKey = "ffmpeg_video_lock:post_media:{$media->id}";
    $lock = Cache::lock($lockKey, 360);
    $lock->get();

    $transcoderMock = mock(VideoTranscodingService::class);
    $transcoderMock->shouldNotReceive('transcode');

    $job = new ProcessSquareVideoJob($media);
    $job->handle(app(VideoProcessingService::class), $transcoderMock);

    // Le média ne doit pas avoir changé d'état car le verrou a bloqué l'exécution
    $media->refresh();
    expect($media->status)->toBe(MediaProcessingStatus::Pending);

    $lock->release();
});

it('débloque les vidéos bloquées indéfiniment en processing via clean-stuck', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $stuckMedia = PostMedia::create([
        'post_id' => $post->id,
        'media_url' => 'http://localhost/storage/square/videos/stuck.mp4',
        'media_type' => 'video',
        'status' => MediaProcessingStatus::Processing,
        'processing_started_at' => now()->subMinutes(30), // Bloqué depuis 30 minutes
    ]);

    Artisan::call('media:clean-stuck', ['--timeout' => 15]);

    $stuckMedia->refresh();

    expect($stuckMedia->status)->toBe(MediaProcessingStatus::Failed)
        ->and($stuckMedia->error_message)->toContain('Timeout : le traitement a dépassé');
});

it('nettoie les fichiers temporaires après traitement', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create(['user_id' => $user->id]);

    $tempFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'trimmed_temp_test_'.uniqid().'.mp4';
    @file_put_contents($tempFilePath, 'temporary trimmed content to clean');
    expect(file_exists($tempFilePath))->toBeTrue();

    Storage::disk('public')->put('square/videos/sample_test.mp4', 'dummy video');
    $media = PostMedia::create([
        'post_id' => $post->id,
        'media_url' => Storage::disk('public')->url('square/videos/sample_test.mp4'),
        'media_type' => 'video',
        'status' => MediaProcessingStatus::Pending,
    ]);

    $job = new ProcessSquareVideoJob($media);
    $job->addTempFileToClean($tempFilePath);
    $job->handle(app(VideoProcessingService::class), app(VideoTranscodingService::class));

    // Le fichier temporaire suivi a été nettoyé
    expect(file_exists($tempFilePath))->toBeFalse();
});
