<?php

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\User;
use Database\Seeders\SoundSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(SoundSeeder::class);
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

test('le catalogue retourne la liste des sons du catalogue et permet le filtre par genre', function () {
    $response = $this->getJson('/api/reels/sounds?genre=afro');

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'title', 'artist', 'genre', 'uses_count']]]);

    $data = $response->json('data');
    foreach ($data as $item) {
        expect($item['genre'])->toBe('afro');
    }
});

test('la recherche de sons fonctionne sur le titre et l artiste', function () {
    $response = $this->getJson('/api/reels/sounds?search=Makossa');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'makossa-sun');
});

test('un son original peut etre resolu a partir d un identifiant orig reel_id', function () {
    $reel = Reel::factory()->create(['user_id' => $this->user->id]);

    $response = $this->getJson("/api/reels/sounds/orig:{$reel->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', "orig:{$reel->id}")
        ->assertJsonPath('data.original', true);
});

test('les reels utilisant un son listent la video originale en premier', function () {
    $originReel = Reel::factory()->create(['user_id' => $this->user->id, 'status' => ReelStatus::Ready]);
    $soundId = "orig:{$originReel->id}";

    Sound::create([
        'id' => $soundId,
        'title' => 'Son original',
        'artist' => $this->user->name,
        'genre' => 'afro',
        'is_original' => true,
        'original_reel_id' => $originReel->id,
        'user_id' => $this->user->id,
    ]);

    $otherReel = Reel::factory()->create([
        'status' => ReelStatus::Ready,
        'sound_id' => $soundId,
        'likes_count' => 100, // Plus de likes mais ne doit pas passer avant l'original
    ]);

    $response = $this->getJson("/api/reels/sounds/{$soundId}/reels");

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', (string) $originReel->id)
        ->assertJsonPath('data.1.id', (string) $otherReel->id);
});

test('un utilisateur peut ajouter un son a ses favoris et lister ses favoris', function () {
    // Ajouter en favori
    $response = $this->postJson('/api/reels/sounds/afro-chill/favourite');
    $response->assertStatus(200)
        ->assertJsonPath('data.is_favourite', true);

    // Vérifier la liste des favoris
    $favsResponse = $this->getJson('/api/reels/sounds/user/favourites');
    $favsResponse->assertStatus(200)
        ->assertJsonPath('data.0', 'afro-chill');

    // Retirer des favoris
    $responseUnfav = $this->postJson('/api/reels/sounds/afro-chill/favourite');
    $responseUnfav->assertStatus(200)
        ->assertJsonPath('data.is_favourite', false);
});

test('un auteur peut verrouiller et deverrouiller son son original', function () {
    $reel = Reel::factory()->create(['user_id' => $this->user->id]);

    $response = $this->putJson("/api/reels/{$reel->id}/original-sound/lock", [
        'locked' => true,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.locked', true);

    $unlockResponse = $this->putJson("/api/reels/{$reel->id}/original-sound/lock", [
        'locked' => false,
    ]);

    $unlockResponse->assertStatus(200)
        ->assertJsonPath('data.locked', false);
});

test('les URLs des sons musicaux se diffusent sans erreur 403 de disque prive', function () {
    $response = $this->getJson('/api/reels/sounds');
    $response->assertStatus(200);

    $audioUrl = $response->json('data.0.audio_url');
    expect($audioUrl)->not->toBeNull();

    $audioResponse = $this->get($audioUrl);
    $audioResponse->assertStatus(200)
        ->assertHeader('Content-Type', 'audio/mpeg');
});

test('les sons affichent le nombre reel de reels sans base_uses invente', function () {
    $sound = Sound::find('coupe-decale');
    expect($sound)->not->toBeNull();
    expect($sound->base_uses)->toBe(0);

    // Initialement 0 vidéo
    $response = $this->getJson('/api/reels/sounds/coupe-decale');
    $response->assertStatus(200)
        ->assertJsonPath('data.uses_count', 0)
        ->assertJsonPath('data.uses', '0 vidéos');

    // Création de 2 reels utilisant ce son
    $reel1 = Reel::factory()->create([
        'user_id' => $this->user->id,
        'sound_id' => 'coupe-decale',
        'status' => ReelStatus::Ready,
    ]);
    $reel2 = Reel::factory()->create([
        'user_id' => $this->user->id,
        'sound_id' => 'coupe-decale',
        'status' => ReelStatus::Ready,
    ]);

    $responseWithReels = $this->getJson('/api/reels/sounds/coupe-decale');
    $responseWithReels->assertStatus(200)
        ->assertJsonPath('data.uses_count', 2)
        ->assertJsonPath('data.uses', '2 vidéos');

    // Suppression d'un reel
    $reel1->delete();

    $responseAfterDelete = $this->getJson('/api/reels/sounds/coupe-decale');
    $responseAfterDelete->assertStatus(200)
        ->assertJsonPath('data.uses_count', 1)
        ->assertJsonPath('data.uses', '1 vidéos');
});

test('GET /api/sounds renvoie provenance et license_type pour chaque son', function () {
    $response = $this->getJson('/api/sounds');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'artist',
                    'provenance',
                    'license_type',
                ],
            ],
        ]);

    $data = $response->json('data');
    expect(count($data))->toBeGreaterThan(0);

    $allowedProvenances = ['bango', 'partner', 'user', 'royalty_free'];
    $allowedLicenseTypes = ['bango', 'partner', 'user', 'royalty_free'];

    foreach ($data as $sound) {
        expect($sound['provenance'])->not->toBeNull()
            ->and(in_array($sound['provenance'], $allowedProvenances, true))->toBeTrue()
            ->and($sound['license_type'])->not->toBeNull()
            ->and(in_array($sound['license_type'], $allowedLicenseTypes, true))->toBeTrue();
    }
});

test('GET /api/sounds permet de filtrer les sons par provenance', function () {
    // Créer un son partenaire
    Sound::create([
        'id' => 'partner-groove',
        'title' => 'Partner Groove',
        'artist' => 'Partner Artist',
        'genre' => 'afro',
        'provenance' => 'partner',
        'license_type' => 'royalty_free',
        'rights_status' => 'verified',
        'is_published' => true,
        'published_at' => now(),
    ]);

    $responsePartner = $this->getJson('/api/sounds?provenance=partner');
    $responsePartner->assertStatus(200);

    $partnerData = $responsePartner->json('data');
    expect(count($partnerData))->toBeGreaterThanOrEqual(1);
    foreach ($partnerData as $sound) {
        expect($sound['provenance'])->toBe('partner');
    }

    $responseBango = $this->getJson('/api/sounds?provenance=bango');
    $responseBango->assertStatus(200);

    $bangoData = $responseBango->json('data');
    expect(count($bangoData))->toBeGreaterThan(0);
    foreach ($bangoData as $sound) {
        expect($sound['provenance'])->toBe('bango');
    }
});
