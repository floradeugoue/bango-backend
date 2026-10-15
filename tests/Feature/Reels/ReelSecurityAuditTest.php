<?php

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Reel\ReelView;
use App\Models\Reel\Sound;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use App\Services\Reel\CloudflareStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->alice = User::factory()->create(['name' => 'Alice']);
    $this->bob = User::factory()->create(['name' => 'Bob']);
});

test('un utilisateur ne peut pas consulter ni modifier les brouillons d un autre utilisateur', function () {
    $draft = Reel::factory()->draft()->create([
        'user_id' => $this->alice->id,
        'caption' => 'Secret Alice Draft',
    ]);

    // Bob tente de lire le brouillon d'Alice
    Passport::actingAs($this->bob);
    $response = $this->getJson("/api/reels/drafts/{$draft->id}");
    $response->assertStatus(404);

    // Bob tente de supprimer le brouillon d'Alice
    $deleteResponse = $this->deleteJson("/api/reels/drafts/{$draft->id}");
    $deleteResponse->assertStatus(404);

    // Le brouillon existe toujours
    expect(Reel::where('id', $draft->id)->whereNull('deleted_at')->exists())->toBeTrue();
});

test('un utilisateur ne peut pas ecraser ou retrograder un reel publie vers un brouillon', function () {
    $publishedReel = Reel::factory()->create([
        'user_id' => $this->alice->id,
        'status' => ReelStatus::Ready,
        'caption' => 'Reel déjà publié',
    ]);

    // Alice tente de sauvegarder ce reel comme brouillon
    Passport::actingAs($this->alice);
    $response = $this->postJson('/api/reels/drafts', [
        'id' => (string) $publishedReel->id,
        'caption' => 'Tentative de rétrogradation',
        'composition' => ['clips' => []],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['draft']);

    expect($publishedReel->fresh()->status)->toBe(ReelStatus::Ready);
});

test('un utilisateur ne peut pas publier le brouillon d un autre utilisateur', function () {
    $draft = Reel::factory()->draft()->create([
        'user_id' => $this->alice->id,
        'caption' => 'Brouillon d Alice',
        'video_url' => 'https://storage.bango.app/reels/alice.mp4',
    ]);

    // Bob tente de publier le brouillon d'Alice
    Passport::actingAs($this->bob);
    $response = $this->postJson('/api/reels/publish', [
        'draft_id' => (string) $draft->id,
        'caption' => 'Vol de publication',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['draft_id']);
    expect($draft->fresh()->status)->toBe(ReelStatus::Draft);
});

test('un utilisateur ne peut pas associer un son original verrouille appartenant a autrui', function () {
    // Alice crée un son original verrouillé
    $lockedSound = Sound::factory()->create([
        'id' => 'orig:alice_sound_1',
        'user_id' => $this->alice->id,
        'is_original' => true,
        'is_locked' => true,
        'title' => 'Son privé d Alice',
    ]);

    // Bob tente d'associer ce son dans un brouillon
    Passport::actingAs($this->bob);
    $response = $this->postJson('/api/reels/drafts', [
        'caption' => 'Tentative avec son verrouillé',
        'sound_id' => $lockedSound->id,
        'composition' => ['clips' => []],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sound_id']);

    // Alice peut par contre utiliser son propre son verrouillé
    Passport::actingAs($this->alice);
    $aliceResponse = $this->postJson('/api/reels/drafts', [
        'caption' => 'Alice utilise son son',
        'sound_id' => $lockedSound->id,
        'composition' => ['clips' => []],
    ]);

    $aliceResponse->assertStatus(200);
});

test('le webhook Cloudflare Stream rejette les signatures invalides et les attaques par rejeu', function () {
    Config::set('services.cloudflare_stream.webhook_secret', 'secret-key-12345');
    app()->instance(CloudflareStreamService::class, new CloudflareStreamService);

    $reel = Reel::factory()->create([
        'stream_media_id' => 'cf-video-sec-001',
        'status' => ReelStatus::Processing,
    ]);

    $payload = json_encode([
        'uid' => 'cf-video-sec-001',
        'status' => ['state' => 'ready'],
    ]);

    // 1. Signature totalement invalide
    $invalidResponse = $this->call(
        'POST',
        '/api/webhooks/cloudflare-stream',
        [],
        [],
        [],
        ['HTTP_WEBHOOK_SIGNATURE' => 'time='.time().',sig1=invalidsignature', 'CONTENT_TYPE' => 'application/json'],
        $payload
    );

    $invalidResponse->assertStatus(401);
    expect($reel->fresh()->status)->toBe(ReelStatus::Processing);

    // 2. Attaque par rejeu : signature mathématiquement valide mais timestamp vieux de 10 minutes (600s > 300s)
    $expiredTime = time() - 600;
    $expiredSig = hash_hmac('sha256', "{$expiredTime}.{$payload}", 'secret-key-12345');

    $replayResponse = $this->call(
        'POST',
        '/api/webhooks/cloudflare-stream',
        [],
        [],
        [],
        ['HTTP_WEBHOOK_SIGNATURE' => "time={$expiredTime},sig1={$expiredSig}", 'CONTENT_TYPE' => 'application/json'],
        $payload
    );

    $replayResponse->assertStatus(401);
    expect($reel->fresh()->status)->toBe(ReelStatus::Processing);

    // 3. Signature valide avec timestamp récent
    $validTime = time();
    $validSig = hash_hmac('sha256', "{$validTime}.{$payload}", 'secret-key-12345');

    $validResponse = $this->call(
        'POST',
        '/api/webhooks/cloudflare-stream',
        [],
        [],
        [],
        ['HTTP_WEBHOOK_SIGNATURE' => "time={$validTime},sig1={$validSig}", 'CONTENT_TYPE' => 'application/json'],
        $payload
    );

    $validResponse->assertStatus(200);
    expect($reel->fresh()->status)->toBe(ReelStatus::Ready);
});

test('un webhook ne peut pas faire passer un Reel en etat draft directement vers ready', function () {
    Config::set('services.cloudflare_stream.webhook_secret', null); // Mode local
    app()->instance(CloudflareStreamService::class, new CloudflareStreamService);

    $draftReel = Reel::factory()->draft()->create([
        'stream_media_id' => 'cf-video-draft-bypass',
    ]);

    $payload = [
        'uid' => 'cf-video-draft-bypass',
        'status' => ['state' => 'ready'],
    ];

    $response = $this->postJson('/api/webhooks/cloudflare-stream', $payload);
    $response->assertStatus(400);

    // Le brouillon n'a pas été publié frauduleusement
    expect($draftReel->fresh()->status)->toBe(ReelStatus::Draft);
});

test('la suppression d un commentaire parent supprime en cascade ses reponses et decremente fidelement le compteur', function () {
    $reel = Reel::factory()->create([
        'user_id' => $this->alice->id,
        'comments_count' => 0,
    ]);

    // Alice ajoute un commentaire parent
    $parent = ReelComment::create([
        'reel_id' => $reel->id,
        'user_id' => $this->alice->id,
        'parent_id' => null,
        'body' => 'Parent comment',
    ]);
    $reel->increment('comments_count');

    // Bob et Charlie ajoutent deux réponses
    $reply1 = ReelComment::create([
        'reel_id' => $reel->id,
        'user_id' => $this->bob->id,
        'parent_id' => $parent->id,
        'body' => 'Reply 1',
    ]);
    $reply2 = ReelComment::create([
        'reel_id' => $reel->id,
        'user_id' => $this->bob->id,
        'parent_id' => $parent->id,
        'body' => 'Reply 2',
    ]);
    $reel->increment('comments_count', 2);
    $parent->increment('replies_count', 2);

    expect($reel->fresh()->comments_count)->toBe(3);

    // Alice supprime son commentaire parent
    Passport::actingAs($this->alice);
    $response = $this->deleteJson("/api/reels/comments/{$parent->id}");
    $response->assertStatus(200);

    // Les réponses doivent être supprimées en cascade (soft-delete)
    expect($parent->fresh()->trashed())->toBeTrue()
        ->and($reply1->fresh()->trashed())->toBeTrue()
        ->and($reply2->fresh()->trashed())->toBeTrue();

    // Le compteur du Reel doit être exactement décrémenté de 3 (1 parent + 2 réponses)
    expect($reel->fresh()->comments_count)->toBe(0);
});

test('l anonymisation de l IP et la deduplication des vues fonctionnent fidelement', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $reel = Reel::factory()->create(['views_count' => 0]);

    // Première vue anonyme avec IP
    $res1 = $this->postJson("/api/reels/{$reel->id}/view", [], ['REMOTE_ADDR' => '192.168.1.50']);
    $res1->assertStatus(200)
        ->assertJsonPath('data.viewed', true)
        ->assertJsonPath('data.views_count', 1);

    // Deuxième vue immédiate avec même IP : dédupliquée
    $res2 = $this->postJson("/api/reels/{$reel->id}/view", [], ['REMOTE_ADDR' => '192.168.1.50']);
    $res2->assertStatus(200)
        ->assertJsonPath('data.viewed', false)
        ->assertJsonPath('data.views_count', 1);

    // Vérification que l'adresse IP stockée est bien hachée et non stockée en clair
    $viewRecord = ReelView::where('reel_id', $reel->id)->first();
    expect($viewRecord)->not->toBeNull()
        ->and($viewRecord->ip_address)->not->toBe('192.168.1.50');
});

test('un utilisateur bloque ne peut pas acceder a un reel individuel', function () {
    $reel = Reel::factory()->create([
        'user_id' => $this->alice->id,
        'status' => ReelStatus::Ready,
    ]);

    // Alice bloque Bob
    $this->alice->blockedUsers()->attach($this->bob->id);

    // Bob tente d'accéder au Reel d'Alice
    Passport::actingAs($this->bob);
    $response = $this->getJson("/api/reels/{$reel->id}");
    $response->assertStatus(404);

    // Un tiers non bloqué peut y accéder
    $charlie = User::factory()->create();
    Passport::actingAs($charlie);
    $charlieResponse = $this->getJson("/api/reels/{$reel->id}");
    $charlieResponse->assertStatus(200);
});

test('le nettoyage des fichiers temporaires supprime les fichiers expires sans toucher aux medias actifs', function () {
    Storage::fake('public');
    Config::set('media.disk', 'public');

    $storageService = app(MediaStorageService::class);

    // Créer un fichier temporaire ancien (48h)
    $oldTempFile = 'reels/temp/old_temp_file.mp4';
    Storage::disk('public')->put($oldTempFile, 'old temp content');
    touch(Storage::disk('public')->path($oldTempFile), time() - (48 * 3600));

    // Créer un fichier temporaire récent (1h)
    $recentTempFile = 'reels/temp/recent_temp_file.mp4';
    Storage::disk('public')->put($recentTempFile, 'recent temp content');

    // Nettoyer les fichiers de plus de 24h
    $result = $storageService->cleanTemporaryFiles(24, 'public');

    expect($result['deleted_count'])->toBe(1)
        ->and(Storage::disk('public')->exists($oldTempFile))->toBeFalse()
        ->and(Storage::disk('public')->exists($recentTempFile))->toBeTrue();
});
