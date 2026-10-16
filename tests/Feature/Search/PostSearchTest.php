<?php

use App\Enums\Square\PostAudience;
use App\Models\Profile\Follow;
use App\Models\Profile\UserBlock;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('permet de rechercher des publications par mot-clé avec succès', function () {
    $author = User::factory()->create(['status' => 'active']);

    $post1 = Post::create([
        'user_id' => $author->id,
        'body' => 'Superbe appartement à louer à Douala Bonapriso',
        'audience' => PostAudience::Public,
        'likes_count' => 10,
    ]);

    $post2 = Post::create([
        'user_id' => $author->id,
        'body' => 'Recette traditionnelle de ndolè camerounais',
        'audience' => PostAudience::Public,
        'likes_count' => 5,
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/search/posts?q=appartement');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'body', 'authorName', 'likes_count'],
            ],
            'links',
            'meta' => ['total', 'current_page'],
        ]);

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect((string) $data[0]['id'])->toBe((string) $post1->id);
});

it('est insensible à la casse lors de la recherche', function () {
    $author = User::factory()->create(['status' => 'active']);

    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Immense terrain titré à Kribi bord de mer',
        'audience' => PostAudience::Public,
        'likes_count' => 2,
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/search/posts?q=KRIBI');

    $response->assertStatus(200);
    $data = $response->json('data');
    expect(count($data))->toBe(1);
    expect((string) $data[0]['id'])->toBe((string) $post->id);
});

it('valide la longueur minimale et maximale du terme de recherche', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    // Paramètre q manquant
    getJson('/api/search/posts')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);

    // Paramètre q trop court (1 caractère)
    getJson('/api/search/posts?q=a')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);

    // Paramètre q valide (2 caractères)
    getJson('/api/search/posts?q=ok')
        ->assertStatus(200);

    // Paramètre q trop long (> 100 caractères)
    $tooLong = str_repeat('a', 101);
    getJson("/api/search/posts?q={$tooLong}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['q']);
});

it('classe les résultats avec pertinence et likes', function () {
    $author = User::factory()->create(['status' => 'active']);

    $postLowLikes = Post::create([
        'user_id' => $author->id,
        'body' => 'Boutique de vêtements tendance à Yaoundé',
        'audience' => PostAudience::Public,
        'likes_count' => 2,
        'created_at' => now()->subDays(2),
    ]);

    $postHighLikes = Post::create([
        'user_id' => $author->id,
        'body' => 'Boutique chic au centre-ville',
        'audience' => PostAudience::Public,
        'likes_count' => 50,
        'created_at' => now()->subDay(),
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/search/posts?q=boutique');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBe(2);
    // Le post le plus liké avec le terme en début ressort en tête
    expect((string) $data[0]['id'])->toBe((string) $postHighLikes->id);
    expect((string) $data[1]['id'])->toBe((string) $postLowLikes->id);
});

it('exclut les publications archivées, supprimées ou d auteurs inactifs', function () {
    $author = User::factory()->create(['status' => 'active']);
    $suspendedAuthor = User::factory()->create(['status' => 'suspended']);

    $activePost = Post::create([
        'user_id' => $author->id,
        'body' => 'Villa moderne avec piscine à louer',
        'audience' => PostAudience::Public,
        'is_archived' => false,
    ]);

    $archivedPost = Post::create([
        'user_id' => $author->id,
        'body' => 'Villa avec jardin à louer',
        'audience' => PostAudience::Public,
        'is_archived' => true,
    ]);

    $deletedPost = Post::create([
        'user_id' => $author->id,
        'body' => 'Villa vue panoramique à louer',
        'audience' => PostAudience::Public,
    ]);
    $deletedPost->delete();

    $suspendedAuthorPost = Post::create([
        'user_id' => $suspendedAuthor->id,
        'body' => 'Villa disponible immédiatement',
        'audience' => PostAudience::Public,
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/search/posts?q=villa');
    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (string) $id)->all();
    expect($ids)->toContain((string) $activePost->id);
    expect($ids)->not->toContain((string) $archivedPost->id);
    expect($ids)->not->toContain((string) $deletedPost->id);
    expect($ids)->not->toContain((string) $suspendedAuthorPost->id);
});

it('respecte les restrictions d audience des publications', function () {
    $author = User::factory()->create(['status' => 'active']);

    $followersPost = Post::create([
        'user_id' => $author->id,
        'body' => 'Secret exclusif réservé à mes followers',
        'audience' => PostAudience::Followers,
    ]);

    $publicPost = Post::create([
        'user_id' => $author->id,
        'body' => 'Annonce visible par tout le monde',
        'audience' => PostAudience::Public,
    ]);

    $nonFollower = User::factory()->create(['status' => 'active']);
    Passport::actingAs($nonFollower);

    // Un non-follower ne doit pas voir le post "followers"
    $res1 = getJson('/api/search/posts?q=exclusif');
    expect($res1->json('data'))->toBeEmpty();

    // Après follow de l'auteur, le post devient trouvable
    Follow::create(['follower_id' => $nonFollower->id, 'following_id' => $author->id]);

    $res2 = getJson('/api/search/posts?q=exclusif');
    expect(count($res2->json('data')))->toBe(1);
    expect((string) $res2->json('data.0.id'))->toBe((string) $followersPost->id);
});

it('exclut les publications des utilisateurs bloqués', function () {
    $author = User::factory()->create(['status' => 'active']);
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Publication de test pour blocage mutuel',
        'audience' => PostAudience::Public,
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    UserBlock::create(['blocker_id' => $viewer->id, 'blocked_id' => $author->id]);

    Passport::actingAs($viewer);

    $response = getJson('/api/search/posts?q=blocage');
    $response->assertStatus(200);
    expect($response->json('data'))->toBeEmpty();
});

it('prend en charge la pagination des résultats de recherche', function () {
    $author = User::factory()->create(['status' => 'active']);

    for ($i = 1; $i <= 5; $i++) {
        Post::create([
            'user_id' => $author->id,
            'body' => "Formation numérique BANGO session {$i}",
            'audience' => PostAudience::Public,
            'likes_count' => $i,
        ]);
    }

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/search/posts?q=formation&per_page=2&page=1');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBe(2);
    expect($response->json('meta.total'))->toBe(5);
    expect($response->json('meta.current_page'))->toBe(1);
});
