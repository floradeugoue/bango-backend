<?php

use App\Models\Auth\Role;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');

    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
    $this->userRole = Role::firstOrCreate(['slug' => 'user'], ['name' => 'User']);

    $this->admin = User::factory()->create(['role_id' => $this->adminRole->id]);
    $this->regularUser = User::factory()->create(['role_id' => $this->userRole->id]);
});

test('les utilisateurs non connectés reçoivent 401 sur les endpoints d administration des sons', function () {
    $response = getJson('/api/admin/sounds');
    $response->assertStatus(401);
});

test('les utilisateurs non administrateurs reçoivent 403 sur les endpoints d administration des sons', function () {
    Passport::actingAs($this->regularUser, ['*']);

    $response = getJson('/api/admin/sounds');
    $response->assertStatus(403);
});

test('un administrateur peut lister tous les sons avec filtres avancés', function () {
    Passport::actingAs($this->admin, ['*']);

    Sound::factory()->create([
        'id' => 'sound-published',
        'title' => 'Afro Vibes',
        'genre' => 'afro',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    Sound::factory()->create([
        'id' => 'sound-pending',
        'title' => 'Makossa Dance',
        'genre' => 'makossa',
        'rights_status' => 'pending',
        'is_published' => false,
    ]);

    $res = getJson('/api/admin/sounds?genre=afro');
    $res->assertStatus(200);

    $ids = collect($res->json('data'))->pluck('id')->all();
    expect($ids)->toContain('sound-published')
        ->not->toContain('sound-pending');
});

test('un administrateur peut importer un fichier audio réel avec provenance et licence dans sounds/catalogue/', function () {
    Passport::actingAs($this->admin, ['*']);

    $audioFile = UploadedFile::fake()->create('track.mp3', 4000, 'audio/mpeg');

    $response = postJson('/api/admin/sounds', [
        'title' => 'Benskin Moderne',
        'artist' => 'Artiste BANGO',
        'genre' => 'traditional',
        'audio_file' => $audioFile,
        'duration_seconds' => 45,
        'provenance' => 'Enregistrement interne Studio BANGO',
        'license_type' => 'proprietary_acquired',
        'license_name' => 'Licence Exclusive BANGO Music',
        'license_url' => 'https://legal.bango.app/licenses/benskin-01',
        'license_terms' => 'Autorisation de synchronisation et de redistribution illimitée au sein de la plateforme BANGO.',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Benskin Moderne')
        ->assertJsonPath('data.artist', 'Artiste BANGO')
        ->assertJsonPath('data.provenance', 'Enregistrement interne Studio BANGO')
        ->assertJsonPath('data.license_type', 'proprietary_acquired')
        ->assertJsonPath('data.rights_status', 'verified')
        ->assertJsonPath('data.is_published', true);

    $soundId = $response->json('data.id');
    $sound = Sound::find($soundId);

    expect($sound)->not->toBeNull()
        ->and($sound->audio_url)->toStartWith('sounds/catalogue/audio_');

    Storage::disk('public')->assertExists($sound->audio_url);
});

test('l importation audio rejette les fichiers non autorisés ou non audio', function () {
    Passport::actingAs($this->admin, ['*']);

    $pdf = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

    $response = postJson('/api/admin/sounds', [
        'title' => 'Faux Audio',
        'artist' => 'Hacker',
        'genre' => 'afro',
        'audio_file' => $pdf,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['audio_file']);
});

test('le système refuse la publication immédiate si les droits ne sont pas vérifiés', function () {
    Passport::actingAs($this->admin, ['*']);

    $audioFile = UploadedFile::fake()->create('test.mp3', 2000, 'audio/mpeg');

    $response = postJson('/api/admin/sounds', [
        'title' => 'Son Non Vérifié',
        'artist' => 'Inconnu',
        'genre' => 'afro',
        'audio_file' => $audioFile,
        'rights_status' => 'pending',
        'is_published' => true, // Tentative de publier sans droits validés
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['is_published']);
});

test('un administrateur peut consulter le détail complet d un son', function () {
    Passport::actingAs($this->admin, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'detail-sound',
        'title' => 'Ambiance Marché',
        'artist' => 'BANGO Sounds',
        'provenance' => 'FMA - Creative Commons',
        'license_type' => 'cc_by',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    $response = getJson("/api/admin/sounds/{$sound->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', 'detail-sound')
        ->assertJsonPath('data.provenance', 'FMA - Creative Commons')
        ->assertJsonPath('data.license_type', 'cc_by');
});

test('un administrateur peut modifier les métadonnées et remplacer le fichier audio', function () {
    Passport::actingAs($this->admin, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'update-sound',
        'title' => 'Ancien Titre',
        'artist' => 'Ancien Artiste',
        'audio_url' => 'sounds/catalogue/old_audio.mp3',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    $newAudio = UploadedFile::fake()->create('new_track.mp3', 3500, 'audio/mpeg');

    $response = postJson("/api/admin/sounds/{$sound->id}", [
        'title' => 'Nouveau Titre Makossa',
        'artist' => 'Nouvel Artiste',
        'audio_file' => $newAudio,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Nouveau Titre Makossa')
        ->assertJsonPath('data.artist', 'Nouvel Artiste');

    $fresh = $sound->fresh();
    expect($fresh->audio_url)->toStartWith('sounds/catalogue/audio_')
        ->and($fresh->audio_url)->not->toBe('sounds/catalogue/old_audio.mp3');

    Storage::disk('public')->assertExists($fresh->audio_url);
});

test('un administrateur peut activer ou désactiver la publication d un son', function () {
    Passport::actingAs($this->admin, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'toggle-sound',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    // Désactivation
    $resDeactivate = patchJson("/api/admin/sounds/{$sound->id}/publication", [
        'is_published' => false,
    ]);

    $resDeactivate->assertStatus(200)
        ->assertJsonPath('data.is_published', false);

    expect($sound->fresh()->is_published)->toBeFalse();

    // Réactivation
    $resReactivate = patchJson("/api/admin/sounds/{$sound->id}/publication", [
        'is_published' => true,
    ]);

    $resReactivate->assertStatus(200)
        ->assertJsonPath('data.is_published', true);

    expect($sound->fresh()->is_published)->toBeTrue();
});

test('le déclassement des droits dépublie automatiquement le son', function () {
    Passport::actingAs($this->admin, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'rights-sound',
        'rights_status' => 'verified',
        'is_published' => true,
    ]);

    $res = patchJson("/api/admin/sounds/{$sound->id}/rights", [
        'rights_status' => 'rejected',
    ]);

    $res->assertStatus(200)
        ->assertJsonPath('data.rights_status', 'rejected')
        ->assertJsonPath('data.is_published', false);

    $fresh = $sound->fresh();
    expect($fresh->rights_status)->toBe('rejected')
        ->and($fresh->is_published)->toBeFalse();
});

test('le retrait du catalogue désactive le son sans supprimer physiquement le fichier', function () {
    Passport::actingAs($this->admin, ['*']);

    $filePath = 'sounds/catalogue/audio_sample.mp3';
    Storage::disk('public')->put($filePath, 'sample audio');

    $sound = Sound::factory()->create([
        'id' => 'remove-sound',
        'audio_url' => $filePath,
        'is_published' => true,
        'is_removed' => false,
    ]);

    $response = deleteJson("/api/admin/sounds/{$sound->id}");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $fresh = $sound->fresh();
    expect($fresh->is_removed)->toBeTrue()
        ->and($fresh->is_published)->toBeFalse();

    // Le fichier physique existe TOUJOURS pour préserver les Reels existants
    Storage::disk('public')->assertExists($filePath);
});

test('un son retiré peut être restauré par un administrateur', function () {
    Passport::actingAs($this->admin, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'restore-sound',
        'is_removed' => true,
        'is_published' => false,
    ]);

    $response = postJson("/api/admin/sounds/{$sound->id}/restore");

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($sound->fresh()->is_removed)->toBeFalse();
});

test('le système interdit la suppression physique d un son utilisé par des Reels existants', function () {
    Passport::actingAs($this->admin, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'used-sound',
    ]);

    Reel::factory()->create([
        'sound_id' => $sound->id,
        'user_id' => $this->regularUser->id,
    ]);

    $response = deleteJson("/api/admin/sounds/{$sound->id}/force");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['sound']);

    expect(Sound::where('id', 'used-sound')->exists())->toBeTrue();
});

test('l API publique reels/sounds n affiche que les sons publiés et aux droits vérifiés', function () {
    Passport::actingAs($this->regularUser, ['*']);

    // 1. Son valide (publié + vérifié)
    Sound::factory()->create([
        'id' => 'public-valid',
        'title' => 'Son Autorisé',
        'rights_status' => 'verified',
        'is_published' => true,
        'is_removed' => false,
    ]);

    // 2. Son non vérifié
    Sound::factory()->create([
        'id' => 'public-pending',
        'title' => 'Son En Attente',
        'rights_status' => 'pending',
        'is_published' => false,
        'is_removed' => false,
    ]);

    // 3. Son retiré
    Sound::factory()->create([
        'id' => 'public-removed',
        'title' => 'Son Retiré',
        'rights_status' => 'verified',
        'is_published' => false,
        'is_removed' => true,
    ]);

    $res = getJson('/api/reels/sounds');
    $res->assertStatus(200);

    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain('public-valid')
        ->not->toContain('public-pending')
        ->not->toContain('public-removed');
});

test('un Reel existant utilisant un son retiré continue de fonctionner sans erreur', function () {
    Passport::actingAs($this->regularUser, ['*']);

    $sound = Sound::factory()->create([
        'id' => 'legacy-sound',
        'title' => 'Ancien Son Retiré',
        'artist' => 'Artiste Disparu',
        'audio_url' => 'sounds/catalogue/legacy.mp3',
        'is_removed' => true,
        'is_published' => false,
    ]);

    $reel = Reel::factory()->create([
        'sound_id' => $sound->id,
        'caption' => 'Reel avec ancien son',
        'user_id' => $this->regularUser->id,
    ]);

    $res = getJson("/api/reels/{$reel->id}");

    $res->assertStatus(200)
        ->assertJsonPath('data.sound', 'Artiste Disparu · Ancien Son Retiré')
        ->assertJsonPath('data.soundRemoved', true);
});
