<?php

use App\Models\User;
use App\Services\Upload\TusUploadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');
    config(['media.disk' => 'public']);
});

it('promotes completed tus upload to reels media and allows referencing it in draft', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // 1. Initialiser la session Tus
    $mp4Header = pack('N', 24).'ftypmp42'.pack('N', 0).'mp41mp42';
    $fileData = $mp4Header.str_repeat("\x00", 76);
    $length = strlen($fileData);

    $metadata = 'filename '.base64_encode('mon_reel.mp4').',filetype '.base64_encode('video/mp4').',kind '.base64_encode('reel');

    $createRes = postJson('/api/uploads/tus', [], [
        'Upload-Length' => (string) $length,
        'Upload-Metadata' => $metadata,
    ]);
    $sessionId = $createRes->json('id');

    // 2. Envoyer le fragment complet
    $this->call(
        'PATCH',
        "/api/uploads/tus/{$sessionId}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => '0',
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ],
        $fileData
    )->assertStatus(204);

    // 3. Finaliser via l'endpoint de finalisation
    $finalizeRes = postJson("/api/uploads/tus/{$sessionId}/finalize");
    $finalizeRes->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.type', 'video')
        ->assertJsonStructure([
            'data' => [
                'id',
                'path',
                'url',
                'type',
                'mime_type',
                'file_size',
            ],
        ]);

    $mediaData = $finalizeRes->json('data');
    expect($mediaData['path'])->toContain('reels/videos/');

    // 4. Utiliser l'URL du média dans un brouillon de Studio Reel
    $draftRes = postJson('/api/reels/drafts', [
        'caption' => 'Mon premier Reel avec Tus',
        'video_url' => $mediaData['url'],
    ]);

    $draftRes->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('cleans up expired tus upload sessions and deletes their files', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $service = app(TusUploadService::class);
    $session = $service->createSession($user, 1000, ['filename' => 'old_file.mp4']);

    expect(Storage::disk('public')->exists($session->temp_path))->toBeTrue();

    // Expire the session artificially
    $session->expires_at = now()->subHours(2);
    $session->save();

    $cleanedCount = $service->cleanupExpiredSessions();
    expect($cleanedCount)->toBe(1);

    expect(Storage::disk('public')->exists($session->temp_path))->toBeFalse();
    expect($session->fresh()->status)->toBe('expired');
});
