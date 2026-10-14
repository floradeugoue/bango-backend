<?php

use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('crée avec succès une publication texte seul avec extraction de hashtags', function () {
    $user = User::factory()->create([
        'handle' => 'florian_d',
        'display_name' => 'Florian Deugoue',
    ]);

    Passport::actingAs($user);

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Superbe journée à Douala ! #Bango #Cameroun2026',
        'audience' => 'public',
        'comments_by' => 'everyone',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.authorName', 'Florian Deugoue')
        ->assertJsonPath('data.body', 'Superbe journée à Douala ! #Bango #Cameroun2026')
        ->assertJsonPath('data.audience', 'public')
        ->assertJsonPath('data.commentsBy', 'everyone')
        ->assertJsonPath('data.mine', true);

    $this->assertDatabaseHas('posts', [
        'user_id' => $user->id,
        'body' => 'Superbe journée à Douala ! #Bango #Cameroun2026',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    // Vérification des hashtags créés et normalisés
    $this->assertDatabaseHas('hashtags', [
        'normalized_name' => 'bango',
        'posts_count' => 1,
    ]);
    $this->assertDatabaseHas('hashtags', [
        'normalized_name' => 'cameroun2026',
        'posts_count' => 1,
    ]);
});

it('crée avec succès une publication avec carrousel de médias et tags de personnes', function () {
    $author = User::factory()->create(['handle' => 'author_post']);
    $friend = User::factory()->create(['handle' => 'friend_tagged', 'display_name' => 'Ami Taggué']);

    Passport::actingAs($author);

    $payload = [
        'body' => 'Regardez notre nouveau projet immobilier #Immo',
        'audience' => 'public',
        'place' => [
            'label' => 'Akwa Nord',
            'detail' => 'Douala',
            'city' => 'Douala',
            'neighbourhood' => 'Akwa Nord',
        ],
        'media' => [
            [
                'url' => 'https://storage.bango.cm/square/img1.jpg',
                'type' => 'photo',
                'mime_type' => 'image/jpeg',
                'file_size' => 125000,
                'width' => 1080,
                'height' => 1080,
                'display_order' => 0,
            ],
            [
                'url' => 'https://storage.bango.cm/square/img2.jpg',
                'type' => 'photo',
                'mime_type' => 'image/jpeg',
                'file_size' => 180000,
                'width' => 1080,
                'height' => 1350,
                'display_order' => 1,
            ],
        ],
        'tags' => [
            [
                'account_id' => $friend->id,
                'x' => 0.4500,
                'y' => 0.6000,
                'media_index' => 0,
            ],
        ],
    ];

    $response = postJson('/api/square-cr1/posts', $payload);

    $response->assertStatus(201)
        ->assertJsonPath('data.place', 'Akwa Nord')
        ->assertJsonPath('data.photos.0', 'https://storage.bango.cm/square/img1.jpg')
        ->assertJsonPath('data.photos.1', 'https://storage.bango.cm/square/img2.jpg')
        ->assertJsonPath('data.tags.0.accountId', (string) $friend->id)
        ->assertJsonPath('data.tags.0.handle', 'friend_tagged');

    $this->assertDatabaseHas('post_media', [
        'media_url' => 'https://storage.bango.cm/square/img1.jpg',
        'display_order' => 0,
    ]);

    $this->assertDatabaseHas('post_tags', [
        'user_id' => $friend->id,
    ]);
});

it('rejette une publication sans texte et sans média', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $response = postJson('/api/square-cr1/posts', [
        'body' => '   ',
        'media' => [],
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['body']);
});

it('rejette une publication dépassant 10 médias', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $media = [];
    for ($i = 0; $i < 11; $i++) {
        $media[] = [
            'url' => "https://storage.bango.cm/square/img_{$i}.jpg",
            'type' => 'photo',
        ];
    }

    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Trop de médias',
        'media' => $media,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['media']);
});

it('respecte l idempotence en renvoyant le post existant si la même clé est réutilisée', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $idempotencyKey = 'idemp-'.uniqid();

    $payload = [
        'body' => 'Publication avec clé idempotence #Unique',
        'idempotency_key' => $idempotencyKey,
    ];

    $res1 = postJson('/api/square-cr1/posts', $payload);
    $res1->assertStatus(201);
    $postId = $res1->json('data.id');

    // Deuxième tentative avec la même clé
    $res2 = postJson('/api/square-cr1/posts', $payload);
    $res2->assertStatus(200);
    expect($res2->json('data.id'))->toBe($postId);

    // Vérifier qu'un seul enregistrement existe en base
    expect(Post::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
});

it('rejette l accès aux utilisateurs non authentifiés', function () {
    $response = postJson('/api/square-cr1/posts', [
        'body' => 'Post non authentifié',
    ]);

    $response->assertStatus(401);
});
