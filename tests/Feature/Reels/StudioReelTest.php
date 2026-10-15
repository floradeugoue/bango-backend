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
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

test('un utilisateur peut creer et sauvegarder un brouillon Studio Reel', function () {
    $payload = [
        'caption' => 'Mon premier Reel en préparation',
        'topic' => 'tech',
        'duration' => 15,
        'allow_download' => true,
        'comments_closed' => false,
        'composition' => [
            'clips' => [
                ['id' => 'clip-1', 'uri' => 'file:///tmp/clip1.mp4', 'durationMs' => 15000],
            ],
            'maxMs' => 15000,
            'filter' => 'warm',
        ],
    ];

    $response = $this->postJson('/api/reels/drafts', $payload);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.caption', 'Mon premier Reel en préparation');

    $this->assertDatabaseHas('reels', [
        'user_id' => $this->user->id,
        'caption' => 'Mon premier Reel en préparation',
        'status' => ReelStatus::Draft->value,
    ]);
});

test('un utilisateur peut lister et reprendre ses brouillons', function () {
    $draft1 = Reel::factory()->draft()->create(['user_id' => $this->user->id, 'caption' => 'Brouillon 1']);
    $draft2 = Reel::factory()->draft()->create(['user_id' => $this->user->id, 'caption' => 'Brouillon 2']);

    $response = $this->getJson('/api/reels/drafts');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');

    $detailResponse = $this->getJson("/api/reels/drafts/{$draft1->id}");
    $detailResponse->assertStatus(200)
        ->assertJsonPath('data.id', (string) $draft1->id)
        ->assertJsonPath('data.caption', 'Brouillon 1');
});

test('un utilisateur peut supprimer son brouillon', function () {
    $draft = Reel::factory()->draft()->create(['user_id' => $this->user->id]);

    $response = $this->deleteJson("/api/reels/drafts/{$draft->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertSoftDeleted('reels', ['id' => $draft->id]);
});

test('la publication d un reel declenche le traitement asynchrone via Queue Job', function () {
    Queue::fake();

    $draft = Reel::factory()->draft()->create([
        'user_id' => $this->user->id,
        'caption' => 'Reel prêt pour publication',
        'video_url' => 'https://storage.bango.app/reels/my_reel.mp4',
    ]);

    $response = $this->postJson('/api/reels/publish', [
        'draft_id' => $draft->id,
        'caption' => 'Reel finalisé',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'processing');

    Queue::assertPushed(ProcessReelVideoJob::class, function ($job) use ($draft) {
        return $job->reel->id === $draft->id;
    });

    $this->assertDatabaseHas('reels', [
        'id' => $draft->id,
        'status' => ReelStatus::Processing->value,
    ]);
});

test('le televersement de media pour Studio Reel valide le format et la taille', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->create('clip.mp4', 5000, 'video/mp4');

    $response = $this->postJson('/api/reels/media/upload', [
        'file' => $file,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => ['id', 'url', 'type', 'mime_type', 'file_size'],
        ]);
});
