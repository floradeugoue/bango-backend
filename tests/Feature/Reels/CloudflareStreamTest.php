<?php

use App\Enums\Reel\ReelStatus;
use App\Jobs\Reel\ProcessReelVideoJob;
use App\Models\Reel\Reel;
use App\Models\User;
use App\Services\Reel\CloudflareStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('le job ProcessReelVideoJob initialise les renditions et passe le reel en ready', function () {
    $user = User::factory()->create();
    $reel = Reel::factory()->create([
        'user_id' => $user->id,
        'status' => ReelStatus::Processing,
        'video_url' => 'https://storage.bango.app/reels/video.mp4',
        'sound_id' => null, // Doit générer un son original
    ]);

    $cloudflareMock = mock(CloudflareStreamService::class);
    $cloudflareMock->shouldReceive('isConfigured')->andReturn(false);

    $job = new ProcessReelVideoJob($reel);
    $job->handle($cloudflareMock);

    $reel->refresh();

    expect($reel->status)->toBe(ReelStatus::Ready)
        ->and($reel->published_at)->not->toBeNull()
        ->and($reel->renditions)->toHaveKeys(['480p', '720p', '1080p'])
        ->and($reel->sound_id)->toBe("orig:{$reel->id}");
});

test('le webhook Cloudflare Stream met a jour l etat du Reel a ready lors de la finalisation', function () {
    $reel = Reel::factory()->create([
        'stream_media_id' => 'cf-video-uid-abc-123',
        'status' => ReelStatus::Processing,
    ]);

    $payload = [
        'uid' => 'cf-video-uid-abc-123',
        'status' => [
            'state' => 'ready',
        ],
    ];

    $response = $this->postJson('/api/webhooks/cloudflare-stream', $payload);

    $response->assertStatus(200)
        ->assertJson(['status' => 'processed']);

    expect($reel->fresh()->status)->toBe(ReelStatus::Ready)
        ->and($reel->fresh()->error_message)->toBeNull();
});

test('le webhook Cloudflare Stream passe le reel en failed en cas d erreur de transcodage', function () {
    $reel = Reel::factory()->create([
        'stream_media_id' => 'cf-video-uid-err-456',
        'status' => ReelStatus::Processing,
    ]);

    $payload = [
        'uid' => 'cf-video-uid-err-456',
        'status' => [
            'state' => 'error',
            'errorReasonText' => 'Codec non supporté',
        ],
    ];

    $response = $this->postJson('/api/webhooks/cloudflare-stream', $payload);

    $response->assertStatus(200);

    expect($reel->fresh()->status)->toBe(ReelStatus::Failed)
        ->and($reel->fresh()->error_message)->toBe('Codec non supporté');
});
