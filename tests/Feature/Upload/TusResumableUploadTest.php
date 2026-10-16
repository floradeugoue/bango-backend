<?php

use App\Models\Upload\TusUploadSession;
use App\Models\User;
use App\Services\Upload\TusUploadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\delete;
use function Pest\Laravel\getJson;
use function Pest\Laravel\options;
use function Pest\Laravel\patch;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
    config(['media.disk' => 'public']);
});

it('exposes tus 1.0.0 capabilities via OPTIONS request without authentication', function () {
    $response = options('/api/uploads/tus');

    $response->assertStatus(204);
    expect($response->headers->get('Tus-Resumable'))->toBe('1.0.0');
    expect($response->headers->get('Tus-Version'))->toBe('1.0.0');
    expect($response->headers->get('Tus-Max-Size'))->toBe((string) TusUploadService::MAX_UPLOAD_SIZE);
    expect($response->headers->get('Tus-Extension'))->toContain('creation');
    expect($response->headers->get('Tus-Extension'))->toContain('termination');
});

it('rejects unauthenticated requests on tus creation and management endpoints', function () {
    postJson('/api/uploads/tus', [], ['Upload-Length' => '1000'])->assertStatus(401);
    getJson('/api/uploads/tus/00000000-0000-0000-0000-000000000000')->assertStatus(401);
});

it('allows an authenticated user to create a tus upload session with valid headers and metadata', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $metadata = 'filename '.base64_encode('video_vacances.mp4').',filetype '.base64_encode('video/mp4').',kind '.base64_encode('reel');

    $response = postJson('/api/uploads/tus', [], [
        'Upload-Length' => '1048576', // 1 Mo
        'Upload-Metadata' => $metadata,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('upload_length', 1048576)
        ->assertJsonPath('upload_offset', 0)
        ->assertJsonStructure(['id', 'upload_length', 'upload_offset', 'location', 'expires_at']);

    expect($response->headers->get('Tus-Resumable'))->toBe('1.0.0');
    expect($response->headers->get('Location'))->toContain('/api/uploads/tus/');

    $sessionId = $response->json('id');
    $session = TusUploadSession::find($sessionId);
    expect($session)->not->toBeNull();
    expect($session->user_id)->toBe($user->id);
    expect($session->original_filename)->toBe('video_vacances.mp4');
    expect($session->status)->toBe('active');
});

it('rejects creation when upload-length is missing or exceeds maximum size', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Missing upload-length
    postJson('/api/uploads/tus', [])->assertStatus(400);

    // Exceeds max upload size (500 Mo + 1 octet)
    $excessiveSize = TusUploadService::MAX_UPLOAD_SIZE + 1;
    postJson('/api/uploads/tus', [], [
        'Upload-Length' => (string) $excessiveSize,
    ])->assertStatus(413);
});

it('returns the current confirmed offset via HEAD request', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $session = TusUploadSession::create([
        'user_id' => $user->id,
        'upload_length' => 5000,
        'upload_offset' => 1500,
        'mime_type' => 'video/mp4',
        'status' => 'active',
        'temp_path' => "uploads/temp/{$user->id}/test.part",
        'disk' => 'public',
        'expires_at' => now()->addHours(24),
    ]);

    $response = $this->call('HEAD', "/api/uploads/tus/{$session->id}");

    $response->assertStatus(200);
    expect($response->headers->get('Upload-Offset'))->toBe('1500');
    expect($response->headers->get('Upload-Length'))->toBe('5000');
    expect($response->headers->get('Tus-Resumable'))->toBe('1.0.0');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('forbids access to another user tus session', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $session = TusUploadSession::create([
        'user_id' => $user1->id,
        'upload_length' => 5000,
        'upload_offset' => 0,
        'mime_type' => 'video/mp4',
        'status' => 'active',
        'temp_path' => "uploads/temp/{$user1->id}/test.part",
        'disk' => 'public',
        'expires_at' => now()->addHours(24),
    ]);

    Passport::actingAs($user2);

    $this->call('HEAD', "/api/uploads/tus/{$session->id}")->assertStatus(403);
    patch("/api/uploads/tus/{$session->id}", [], [
        'Content-Type' => 'application/offset+octet-stream',
        'Upload-Offset' => '0',
    ])->assertStatus(403);
    delete("/api/uploads/tus/{$session->id}")->assertStatus(403);
});

it('rejects patch request with invalid content-type or missing upload-offset', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $session = TusUploadSession::create([
        'user_id' => $user->id,
        'upload_length' => 1000,
        'upload_offset' => 0,
        'mime_type' => 'video/mp4',
        'status' => 'active',
        'temp_path' => "uploads/temp/{$user->id}/test.part",
        'disk' => 'public',
        'expires_at' => now()->addHours(24),
    ]);

    // Invalid content-type (application/json instead of application/offset+octet-stream)
    $this->call('PATCH', "/api/uploads/tus/{$session->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_UPLOAD_OFFSET' => '0',
    ])->assertStatus(415);

    // Missing Upload-Offset header
    $this->call('PATCH', "/api/uploads/tus/{$session->id}", [], [], [], [
        'CONTENT_TYPE' => 'application/offset+octet-stream',
    ])->assertStatus(400);
});

it('accepts chunk uploads and updates offset atomically', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $totalLength = 100;
    $chunk1 = str_repeat('A', 40); // 40 octets

    $createResponse = postJson('/api/uploads/tus', [], [
        'Upload-Length' => (string) $totalLength,
        'Upload-Metadata' => 'filename '.base64_encode('test.mp4'),
    ]);
    $sessionId = $createResponse->json('id');

    // Premier fragment à offset 0
    $patchResponse1 = $this->call(
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
        $chunk1
    );

    $patchResponse1->assertStatus(204);
    expect($patchResponse1->headers->get('Upload-Offset'))->toBe('40');

    $session = TusUploadSession::find($sessionId);
    expect($session->upload_offset)->toBe(40);

    // Consultation intermédiaire par HEAD
    $headResponse = $this->call('HEAD', "/api/uploads/tus/{$sessionId}");
    $headResponse->assertStatus(200);
    expect($headResponse->headers->get('Upload-Offset'))->toBe('40');
});

it('rejects patch request when client offset does not match server offset with 409 conflict', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $createResponse = postJson('/api/uploads/tus', [], [
        'Upload-Length' => '100',
    ]);
    $sessionId = $createResponse->json('id');

    // Server offset is 0, client sends 20
    $conflictResponse = $this->call(
        'PATCH',
        "/api/uploads/tus/{$sessionId}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => '20',
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ],
        str_repeat('X', 20)
    );

    $conflictResponse->assertStatus(409)
        ->assertJsonPath('error', 'TUS_OFFSET_CONFLICT')
        ->assertJsonPath('current_offset', 0)
        ->assertJsonPath('expected_offset', 20);

    expect($conflictResponse->headers->get('Upload-Offset'))->toBe('0');
});

it('supports resuming upload after interruption until complete file is assembled', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    // Create a minimal real MP4 header binary buffer (ftyp box)
    // 00 00 00 18 66 74 79 70 6d 70 34 32 ...
    $mp4Header = pack('N', 24).'ftypmp42'.pack('N', 0).'mp41mp42';
    $mp4Payload = str_repeat("\x00", 76);
    $fullFileContent = $mp4Header.$mp4Payload; // 24 + 76 = 100 bytes
    $totalLength = strlen($fullFileContent);

    $createResponse = postJson('/api/uploads/tus', [], [
        'Upload-Length' => (string) $totalLength,
        'Upload-Metadata' => 'filename '.base64_encode('video.mp4').',filetype '.base64_encode('video/mp4').',kind '.base64_encode('reel'),
    ]);
    $sessionId = $createResponse->json('id');

    // Chunk 1: first 60 bytes
    $chunk1 = substr($fullFileContent, 0, 60);
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
        $chunk1
    )->assertStatus(204);

    // Client resumes after network loss: verifies offset via HEAD
    $head = $this->call('HEAD', "/api/uploads/tus/{$sessionId}");
    $head->assertStatus(200);
    expect($head->headers->get('Upload-Offset'))->toBe('60');

    // Chunk 2: remaining 40 bytes at offset 60
    $chunk2 = substr($fullFileContent, 60, 40);
    $patch2 = $this->call(
        'PATCH',
        "/api/uploads/tus/{$sessionId}",
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/offset+octet-stream',
            'HTTP_UPLOAD_OFFSET' => '60',
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ],
        $chunk2
    );

    $patch2->assertStatus(204);
    expect($patch2->headers->get('Upload-Offset'))->toBe('100');

    // Session is completed
    $session = TusUploadSession::find($sessionId);
    expect($session->status)->toBe('completed');
    expect($session->final_path)->not->toBeNull();
    expect($session->final_url)->not->toBeNull();
});

it('allows terminating a session and cleans up temporary files', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $createResponse = postJson('/api/uploads/tus', [], [
        'Upload-Length' => '500',
    ]);
    $sessionId = $createResponse->json('id');

    $session = TusUploadSession::find($sessionId);
    expect(Storage::disk('public')->exists($session->temp_path))->toBeTrue();

    // Terminate via DELETE
    $deleteResponse = delete("/api/uploads/tus/{$sessionId}");
    $deleteResponse->assertStatus(204);

    expect(Storage::disk('public')->exists($session->temp_path))->toBeFalse();
    expect($session->fresh()->status)->toBe('cancelled');
});
