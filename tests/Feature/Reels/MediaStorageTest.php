<?php

use App\Models\Reel\Reel;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->storageService = app(MediaStorageService::class);
    Storage::fake('public');
    Passport::actingAs($this->user);
});

test('le service de stockage enregistre une vidéo dans reels/videos/{user_id} sur le disque local', function () {
    $file = UploadedFile::fake()->create('clip.mp4', 8000, 'video/mp4');

    $result = $this->storageService->storeReelVideo($file, $this->user, 'public');

    expect($result)->toHaveKeys(['id', 'path', 'url', 'type', 'mime_type', 'file_size', 'disk'])
        ->and($result['type'])->toBe('video')
        ->and($result['mime_type'])->toBe('video/mp4')
        ->and($result['disk'])->toBe('public')
        ->and($result['path'])->toStartWith("reels/videos/{$this->user->id}/video_");

    Storage::disk('public')->assertExists($result['path']);
});

test('le service de stockage sépare les brouillons Studio Reel dans reels/drafts/{user_id}', function () {
    $file = UploadedFile::fake()->create('draft_clip.mp4', 5000, 'video/mp4');

    $result = $this->storageService->storeReelDraftMedia($file, $this->user, 'public');

    expect($result['type'])->toBe('video')
        ->and($result['path'])->toStartWith("reels/drafts/{$this->user->id}/draft_");

    Storage::disk('public')->assertExists($result['path']);
});

test('le service de stockage enregistre une image de couverture dans reels/covers/{user_id}', function () {
    $file = UploadedFile::fake()->image('cover.jpg', 1080, 1920);

    $result = $this->storageService->storeReelCover($file, $this->user, 'public');

    expect($result)->toHaveKeys(['id', 'path', 'url', 'type', 'mime_type', 'width', 'height'])
        ->and($result['type'])->toBe('cover')
        ->and($result['mime_type'])->toBe('image/jpeg')
        ->and($result['path'])->toStartWith("reels/covers/{$this->user->id}/cover_");

    Storage::disk('public')->assertExists($result['path']);
});

test('le service de stockage enregistre une miniature dans reels/thumbnails/{user_id}', function () {
    $file = UploadedFile::fake()->image('thumb.jpg', 720, 1280);

    $result = $this->storageService->storeReelThumbnail($file, $this->user, 'public');

    expect($result['type'])->toBe('thumbnail')
        ->and($result['path'])->toStartWith("reels/thumbnails/{$this->user->id}/thumb_");

    Storage::disk('public')->assertExists($result['path']);
});

test('le service de stockage sépare le catalogue et les sons originaux dans sounds/catalogue et sounds/originals', function () {
    // Son catalogue standard
    $catalogAudio = UploadedFile::fake()->create('music.mp3', 3000, 'audio/mpeg');
    $catalogResult = $this->storageService->storeAudio($catalogAudio, $this->user, false, 'public');

    expect($catalogResult['path'])->toStartWith('sounds/catalogue/audio_')
        ->and($catalogResult['type'])->toBe('audio');
    Storage::disk('public')->assertExists($catalogResult['path']);

    // Son original spécifique à l'utilisateur
    $origAudio = UploadedFile::fake()->create('original.mp3', 2000, 'audio/mpeg');
    $origResult = $this->storageService->storeAudio($origAudio, $this->user, true, 'public');

    expect($origResult['path'])->toStartWith("sounds/originals/{$this->user->id}/audio_");
    Storage::disk('public')->assertExists($origResult['path']);
});

test('le service de stockage sépare les photos et vidéos de Square dans square/images et square/videos', function () {
    // Photo Square
    $imageFile = UploadedFile::fake()->image('post.jpg', 1080, 1080);
    $imageResult = $this->storageService->storeSquareMedia($imageFile, $this->user, 'public');

    expect($imageResult['type'])->toBe('photo')
        ->and($imageResult['path'])->toStartWith("square/images/{$this->user->id}/media_");
    Storage::disk('public')->assertExists($imageResult['path']);

    // Vidéo Square
    $videoFile = UploadedFile::fake()->create('post_video.mp4', 5000, 'video/mp4');
    $videoResult = $this->storageService->storeSquareMedia($videoFile, $this->user, 'public');

    expect($videoResult['type'])->toBe('video')
        ->and($videoResult['path'])->toStartWith("square/videos/{$this->user->id}/media_");
    Storage::disk('public')->assertExists($videoResult['path']);
});

test('le service de stockage gère les fichiers temporaires dans les sous-dossiers temp dédiés', function () {
    $tempFile = UploadedFile::fake()->create('temp_upload.mp4', 2000, 'video/mp4');

    $reelTemp = $this->storageService->storeTempMedia($tempFile, 'reels', 'public');
    expect($reelTemp['path'])->toStartWith('reels/temp/temp_');
    Storage::disk('public')->assertExists($reelTemp['path']);

    $soundTemp = $this->storageService->storeTempMedia($tempFile, 'sounds', 'public');
    expect($soundTemp['path'])->toStartWith('sounds/temp/temp_');
    Storage::disk('public')->assertExists($soundTemp['path']);

    $squareTemp = $this->storageService->storeTempMedia($tempFile, 'square', 'public');
    expect($squareTemp['path'])->toStartWith('square/temp/temp_');
    Storage::disk('public')->assertExists($squareTemp['path']);
});

test('le service rejette les types MIME non autorises et les fichiers trop volumineux', function () {
    // MIME non autorisé (ex: PDF au lieu de vidéo)
    $pdf = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

    expect(fn () => $this->storageService->storeReelVideo($pdf, $this->user, 'public'))
        ->toThrow(ValidationException::class);

    // Image trop volumineuse (> 15 Mo)
    $heavyImage = UploadedFile::fake()->create('huge.jpg', 20 * 1024, 'image/jpeg');

    expect(fn () => $this->storageService->storeReelCover($heavyImage, $this->user, 'public'))
        ->toThrow(ValidationException::class);

    // Audio non autorisé
    expect(fn () => $this->storageService->storeAudio($pdf, $this->user, false, 'public'))
        ->toThrow(ValidationException::class);
});

test('la generation d URL fonctionne de maniere uniforme pour chemins relatifs et absolus et préserve les flux externes', function () {
    $relativePath = 'reels/videos/1/video_123.mp4';
    $url = $this->storageService->getUrl($relativePath, 'public');

    expect($url)->toContain('/storage/reels/videos/1/video_123.mp4');

    // Les URLs de flux externes (Cloudflare Stream) sont préservées telles quelles
    $streamUrl = 'https://customer-example.cloudflarestream.com/abc123xyz/manifest/video.m3u8';
    expect($this->storageService->getUrl($streamUrl))->toBe($streamUrl)
        ->and($this->storageService->isExternalUrl($streamUrl))->toBeTrue();
});

test('la compatibilité avec les anciens chemins et anciennes URLs est préservée', function () {
    // Ancien chemin sans sous-dossier vidéo
    $legacyClipPath = "reels/{$this->user->id}/clips/clip_old.mp4";
    Storage::disk('public')->put($legacyClipPath, 'legacy video content');

    // Vérification de lecture, existence et extraction de chemin
    expect($this->storageService->exists($legacyClipPath, 'public'))->toBeTrue()
        ->and($this->storageService->getUrl($legacyClipPath, 'public'))->toContain("/storage/{$legacyClipPath}");

    $extracted = $this->storageService->extractRelativePath("/storage/{$legacyClipPath}");
    expect($extracted)->toBe($legacyClipPath);

    // Suppression sécurisée de l'ancien chemin
    $deleted = $this->storageService->delete($legacyClipPath, 'public');
    expect($deleted)->toBeTrue()
        ->and(Storage::disk('public')->exists($legacyClipPath))->toBeFalse();
});

test('la suppression d un fichier physique fonctionne et ignore les flux externes', function () {
    $path = "reels/videos/{$this->user->id}/test_delete.mp4";
    Storage::disk('public')->put($path, 'video data');

    expect(Storage::disk('public')->exists($path))->toBeTrue();

    $deleted = $this->storageService->delete($path, 'public');

    expect($deleted)->toBeTrue()
        ->and(Storage::disk('public')->exists($path))->toBeFalse();

    // Tenter de supprimer une URL externe ne lance aucune erreur et retourne false
    $streamUrl = 'https://customer-abc.cloudflarestream.com/manifest.m3u8';
    expect($this->storageService->delete($streamUrl, 'public'))->toBeFalse();
});

test('deleteReelMedia supprime les fichiers locaux associes au Reel sans toucher aux flux externes', function () {
    $videoPath = "reels/videos/{$this->user->id}/video_sample.mp4";
    $coverPath = "reels/covers/{$this->user->id}/cover_sample.jpg";

    Storage::disk('public')->put($videoPath, 'video content');
    Storage::disk('public')->put($coverPath, 'cover content');

    $reel = Reel::factory()->create([
        'user_id' => $this->user->id,
        'video_url' => Storage::disk('public')->url($videoPath),
        'thumbnail_url' => Storage::disk('public')->url($coverPath),
    ]);

    $this->storageService->deleteReelMedia($reel, 'public');

    expect(Storage::disk('public')->exists($videoPath))->toBeFalse()
        ->and(Storage::disk('public')->exists($coverPath))->toBeFalse();
});

test('le service fonctionne de maniere transparente en basculant sur un disque distant type S3 ou R2', function () {
    Storage::fake('r2');

    $file = UploadedFile::fake()->create('remote_clip.mp4', 4000, 'video/mp4');

    $result = $this->storageService->storeReelVideo($file, $this->user, 'r2');

    expect($result['disk'])->toBe('r2')
        ->and($result['path'])->toStartWith("reels/videos/{$this->user->id}/video_");

    Storage::disk('r2')->assertExists($result['path']);
});

test('auditStorage identifie correctement les fichiers et répertoires sans les supprimer', function () {
    $videoPath = "reels/videos/{$this->user->id}/video_orphan.mp4";
    Storage::disk('public')->put($videoPath, 'orphan video');

    $audit = $this->storageService->auditStorage('public');

    expect($audit['total_files'])->toBeGreaterThanOrEqual(1)
        ->and($audit['orphan_files'])->toContain($videoPath);

    // Le fichier orphelin n'a PAS été supprimé
    expect(Storage::disk('public')->exists($videoPath))->toBeTrue();
});
