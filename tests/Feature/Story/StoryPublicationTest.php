<?php

use App\Enums\Story\StoryAudience;
use App\Enums\Story\StoryMediaType;
use App\Enums\Story\StoryStatus;
use App\Models\Reel\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
    Storage::fake('public');
});

test('un utilisateur peut publier une Story image avec succès', function () {
    $image = UploadedFile::fake()->image('story1.jpg', 1080, 1920)->size(2048);

    $response = $this->postJson('/api/stories', [
        'file' => $image,
        'caption' => 'Superbe coucher de soleil à Kribi !',
        'filter' => 'vintage',
        'audience' => 'everyone',
        'allow_replies' => true,
        'allow_archive' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.author.id', $this->user->id)
        ->assertJsonPath('data.audience', StoryAudience::Everyone->value)
        ->assertJsonPath('data.status', StoryStatus::Active->value)
        ->assertJsonPath('data.slides.0.media_type', StoryMediaType::Image->value)
        ->assertJsonPath('data.slides.0.caption', 'Superbe coucher de soleil à Kribi !')
        ->assertJsonPath('data.slides.0.filter', 'vintage');

    $this->assertDatabaseHas('stories', [
        'user_id' => $this->user->id,
        'audience' => StoryAudience::Everyone->value,
        'status' => StoryStatus::Active->value,
    ]);

    $this->assertDatabaseHas('story_slides', [
        'caption' => 'Superbe coucher de soleil à Kribi !',
        'media_type' => StoryMediaType::Image->value,
    ]);
});

test('un utilisateur peut publier une Story vidéo valide', function () {
    $video = UploadedFile::fake()->create('video.mp4', 15000, 'video/mp4');

    $response = $this->postJson('/api/stories', [
        'file' => $video,
        'caption' => 'Vidéo de fête',
        'duration' => 15,
        'audience' => 'everyone',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.slides.0.media_type', StoryMediaType::Video->value)
        ->assertJsonPath('data.slides.0.duration', 15);

    $this->assertDatabaseHas('story_slides', [
        'media_type' => StoryMediaType::Video->value,
        'duration' => 15,
    ]);
});

test('la publication échoue si la photo dépasse 10 Mo', function () {
    $bigImage = UploadedFile::fake()->create('huge.jpg', 12 * 1024, 'image/jpeg');

    $response = $this->postJson('/api/stories', [
        'file' => $bigImage,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('la publication échoue si la vidéo dépasse 50 Mo', function () {
    $bigVideo = UploadedFile::fake()->create('huge.mp4', 55 * 1024, 'video/mp4');

    $response = $this->postJson('/api/stories', [
        'file' => $bigVideo,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('la publication échoue pour un type de fichier non autorisé', function () {
    $pdf = UploadedFile::fake()->create('document.pdf', 1000, 'application/pdf');

    $response = $this->postJson('/api/stories', [
        'file' => $pdf,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('la publication peut intégrer un son du catalogue des Reels', function () {
    $sound = Sound::create([
        'id' => 'sound-bango-01',
        'title' => 'Afrobeat Vibe',
        'artist' => 'Bango Music',
        'duration' => 60,
        'genre' => 'Afrobeats',
        'audio_path' => 'sounds/catalogue/afrobeat.mp3',
        'audio_url' => 'https://storage.bango.app/sounds/afrobeat.mp3',
        'is_catalogue' => true,
    ]);

    $image = UploadedFile::fake()->image('story_sound.jpg', 1080, 1920);

    $response = $this->postJson('/api/stories', [
        'file' => $image,
        'sound_id' => $sound->id,
        'sound_start_at' => 10,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.slides.0.sound.title', 'Afrobeat Vibe')
        ->assertJsonPath('data.slides.0.sound.artist', 'Bango Music')
        ->assertJsonPath('data.slides.0.sound.start_at', 10);
});

test('la publication avec stickers et sondage valide les métadonnées géométriques', function () {
    $image = UploadedFile::fake()->image('story_poll.jpg', 1080, 1920);

    $response = $this->postJson('/api/stories', [
        'file' => $image,
        'stickers' => [
            [
                'type' => 'text',
                'x' => 0.5,
                'y' => 0.2,
                'scale' => 1.2,
                'rotation' => 5.0,
                'data' => ['text' => 'Bonjour le Cameroun !', 'color' => '#ff0000'],
            ],
            [
                'type' => 'poll',
                'x' => 0.5,
                'y' => 0.6,
                'data' => [
                    'question' => 'Avez-vous testé les beignets haricots ?',
                    'options' => ['Oui bien sûr', 'Pas encore'],
                ],
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonCount(2, 'data.slides.0.stickers');

    $this->assertDatabaseHas('story_stickers', [
        'type' => 'poll',
    ]);
});

test('la publication avec audience chosen échoue si la liste choisie est vide', function () {
    $image = UploadedFile::fake()->image('story_chosen.jpg', 1080, 1920);

    $response = $this->postJson('/api/stories', [
        'file' => $image,
        'audience' => 'chosen',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['audience']);
});

test('la publication échoue si le son est retiré ou verrouillé par un autre utilisateur', function () {
    $otherUser = User::factory()->create();

    $removedSound = Sound::create([
        'id' => 'sound-removed-01',
        'title' => 'Son Supprimé',
        'artist' => 'Artiste',
        'is_removed' => true,
    ]);

    $lockedSound = Sound::create([
        'id' => 'sound-locked-01',
        'title' => 'Son Privé',
        'artist' => 'Artiste',
        'user_id' => $otherUser->id,
        'is_locked' => true,
    ]);

    $image = UploadedFile::fake()->image('story.jpg', 1080, 1920);

    // Test son retiré
    $res1 = $this->postJson('/api/stories', [
        'file' => $image,
        'sound_id' => $removedSound->id,
    ]);
    $res1->assertStatus(422)->assertJsonValidationErrors(['sound_id']);

    // Test son verrouillé par autrui
    $res2 = $this->postJson('/api/stories', [
        'file' => $image,
        'sound_id' => $lockedSound->id,
    ]);
    $res2->assertStatus(422)->assertJsonValidationErrors(['sound_id']);
});

test('la publication échoue si les coordonnées de stickers sont en dehors de 0 et 1', function () {
    $image = UploadedFile::fake()->image('story.jpg', 1080, 1920);

    $response = $this->postJson('/api/stories', [
        'file' => $image,
        'stickers' => [
            [
                'type' => 'text',
                'x' => 1.5, // Dépasse 1.0
                'y' => 0.5,
                'data' => ['text' => 'Hors écran'],
            ],
        ],
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['stickers.0.x']);
});

test('la publication échoue si le fichier média est vide (0 octet)', function () {
    $emptyFile = UploadedFile::fake()->create('empty.jpg', 0, 'image/jpeg');

    $response = $this->postJson('/api/stories', [
        'file' => $emptyFile,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['file']);
});
