<?php

use App\Enums\Reel\ReelStatus;
use App\Jobs\Reel\ProcessReelVideoJob;
use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();
    Passport::actingAs($this->user);
});

test('studio reel accepte les formats video reels generes par les cameras mobiles (MP4, MOV, WebM, 3GP, M4V)', function (string $filename, string $mime) {
    $videoFile = UploadedFile::fake()->create($filename, 2048, $mime);

    $response = $this->postJson('/api/reels/media/upload', [
        'file' => $videoFile,
        'kind' => 'clip',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.mime_type', $mime)
        ->assertJsonPath('data.type', 'video');

    $savedPath = $response->json('data.path');
    expect(Storage::disk('public')->exists($savedPath))->toBeTrue();
})->with([
    ['camera_android.mp4', 'video/mp4'],
    ['camera_ios.mov', 'video/quicktime'],
    ['camera_browser.webm', 'video/webm'],
    ['camera_feature_phone.3gp', 'video/3gpp'],
    ['camera_apple.m4v', 'video/x-m4v'],
]);

test('studio reel rejette les faux fichiers et formats interdits', function (string $filename, string $mime) {
    $invalidFile = UploadedFile::fake()->create($filename, 500, $mime);

    $response = $this->postJson('/api/reels/media/upload', [
        'file' => $invalidFile,
        'kind' => 'video',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
})->with([
    ['malicious.exe', 'application/x-msdownload'],
    ['script.php', 'text/x-php'],
    ['document.pdf', 'application/pdf'],
    ['archive.zip', 'application/zip'],
]);

test('studio reel rejette les videos depassant la limite maximale autorisee (100 Mo)', function () {
    // 105 Mo (dépasse 100 Mo = 102400 Ko)
    $oversizedVideo = UploadedFile::fake()->create('huge_camera_shot.mp4', 105 * 1024, 'video/mp4');

    $response = $this->postJson('/api/reels/media/upload', [
        'file' => $oversizedVideo,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('la separation des clips brouillons et videos finales est respectee', function () {
    $clipFile = UploadedFile::fake()->create('camera_take_1.mp4', 1024, 'video/mp4');
    $videoFile = UploadedFile::fake()->create('camera_final.mp4', 1024, 'video/mp4');

    // 1. Upload d'un clip de travail caméra pour brouillon (kind=clip)
    $clipRes = $this->postJson('/api/reels/media/upload', [
        'file' => $clipFile,
        'kind' => 'clip',
    ]);
    $clipRes->assertStatus(201);
    expect($clipRes->json('data.path'))->toContain("reels/drafts/{$this->user->id}/");

    // 2. Upload direct d'une vidéo prête (kind=video)
    $videoRes = $this->postJson('/api/reels/media/upload', [
        'file' => $videoFile,
        'kind' => 'video',
    ]);
    $videoRes->assertStatus(201);
    expect($videoRes->json('data.path'))->toContain("reels/videos/{$this->user->id}/");
});

test('parcours complet de composition multi-clips camera et publication asynchrone', function () {
    Queue::fake();

    // 1. L'utilisateur filme 3 clips successifs avec la caméra
    $clip1 = UploadedFile::fake()->create('cam_segment_1.mp4', 1024, 'video/mp4');
    $clip2 = UploadedFile::fake()->create('cam_segment_2.mov', 1024, 'video/quicktime');
    $clip3 = UploadedFile::fake()->create('cam_segment_3.webm', 1024, 'video/webm');

    $res1 = $this->postJson('/api/reels/media/upload', ['file' => $clip1, 'kind' => 'clip'])->json('data');
    $res2 = $this->postJson('/api/reels/media/upload', ['file' => $clip2, 'kind' => 'clip'])->json('data');
    $res3 = $this->postJson('/api/reels/media/upload', ['file' => $clip3, 'kind' => 'clip'])->json('data');

    // 2. Sauvegarde du brouillon avec la composition des 3 prises de caméra
    $draftPayload = [
        'caption' => 'Ma journée filmée à la caméra',
        'topic' => 'vlog',
        'composition' => [
            'clips' => [
                ['id' => 'c1', 'uri' => $res1['url'], 'durationMs' => 5000, 'rate' => 1, 'source' => 'camera'],
                ['id' => 'c2', 'uri' => $res2['url'], 'durationMs' => 10000, 'rate' => 1, 'source' => 'camera'],
                ['id' => 'c3', 'uri' => $res3['url'], 'durationMs' => 15000, 'rate' => 1, 'source' => 'camera'],
            ],
            'maxMs' => 30000,
            'filter' => 'vintage',
            'filterIntensity' => 0.8,
            'originalVolume' => 1,
            'coverUri' => $res1['url'],
        ],
    ];

    $draftRes = $this->postJson('/api/reels/drafts', $draftPayload);
    $draftRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.duration', 30) // Auto-calculé : 5000 + 10000 + 15000 = 30000 ms -> 30s
        ->assertJsonPath('data.video_url', $res1['url']);

    $draftId = $draftRes->json('data.id');

    // 3. Publication du Reel depuis le brouillon
    $publishRes = $this->postJson('/api/reels/publish', [
        'draft_id' => $draftId,
        'caption' => 'Ma journée filmée à la caméra (Publié)',
    ]);

    $publishRes->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'processing');

    Queue::assertPushed(ProcessReelVideoJob::class, function ($job) use ($draftId) {
        return $job->reel->id === $draftId;
    });

    $reel = Reel::find($draftId);
    expect($reel->status)->toBe(ReelStatus::Processing)
        ->and($reel->duration)->toBe(30)
        ->and($reel->video_url)->toBe($res1['url']);
});
