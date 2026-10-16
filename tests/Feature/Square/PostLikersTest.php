<?php

use App\Models\Profile\UserBlock;
use App\Models\Square\Post;
use App\Models\Square\PostLike;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('retourne la liste paginée des likers d une publication avec badges et informations publiques', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Superbe journée à Yaoundé #Bango',
        'audience' => 'public',
        'likes_count' => 3,
    ]);

    // Créer des likers avec différents types de badges
    $liker1 = User::factory()->create([
        'name' => 'Alice Landlord',
        'handle' => 'alice_landlord',
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => false,
        'status' => 'active',
    ]);

    $liker2 = User::factory()->create([
        'name' => 'Bob Agent',
        'handle' => 'bob_agent',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
        'status' => 'active',
    ]);

    $liker3 = User::factory()->create([
        'name' => 'Charlie Standard',
        'handle' => 'charlie_std',
        'badge' => 'none',
        'is_verified' => false,
        'status' => 'active',
    ]);

    PostLike::create(['post_id' => $post->id, 'user_id' => $liker1->id, 'created_at' => now()->subMinutes(10)]);
    PostLike::create(['post_id' => $post->id, 'user_id' => $liker2->id, 'created_at' => now()->subMinutes(5)]);
    PostLike::create(['post_id' => $post->id, 'user_id' => $liker3->id, 'created_at' => now()]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    $response = getJson("/api/square-cr1/posts/{$post->id}/likers");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'handle',
                    'avatar_url',
                    'avatarUrl',
                    'badge',
                    'badges',
                    'ring_color',
                    'ring_style',
                    'is_verified',
                    'verified',
                    'account_type',
                    'place',
                    'bio',
                    'liked_at',
                    'is_following',
                ],
            ],
            'links',
            'meta' => ['current_page', 'total', 'per_page'],
        ]);

    $data = $response->json('data');
    expect(count($data))->toBe(3);

    // Ordre déterministe décroissant par date de like
    expect($data[0]['id'])->toBe($liker3->id);
    expect($data[1]['id'])->toBe($liker2->id);
    expect($data[2]['id'])->toBe($liker1->id);

    // Vérification des badges
    expect($data[1]['badge'])->toBe('agent');
    expect($data[1]['badges'])->toContain('Agent');
    expect($data[1]['verified'])->toBeTrue();

    expect($data[2]['badge'])->toBe('landlord');
    expect($data[2]['badges'])->toContain('Bailleur');
    expect($data[2]['verified'])->toBeTrue();

    expect($data[0]['badge'])->toBe('none');
    expect($data[0]['verified'])->toBeFalse();

    // Aucune fuite de données privées
    expect($data[0])->not->toHaveKey('email');
    expect($data[0])->not->toHaveKey('password');
    expect($data[0])->not->toHaveKey('phone');
    expect($data[0])->not->toHaveKey('two_factor_secret');
});

it('gère correctement la pagination sans doublons', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post pour test de pagination',
        'audience' => 'public',
        'likes_count' => 5,
    ]);

    $likers = User::factory()->count(5)->create(['status' => 'active']);
    foreach ($likers as $index => $liker) {
        PostLike::create([
            'post_id' => $post->id,
            'user_id' => $liker->id,
            'created_at' => now()->subMinutes(10 - $index),
        ]);
    }

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    $page1 = getJson("/api/square-cr1/posts/{$post->id}/likers?per_page=2&page=1");
    $page1->assertStatus(200);
    $data1 = $page1->json('data');
    expect(count($data1))->toBe(2);

    $page2 = getJson("/api/square-cr1/posts/{$post->id}/likers?per_page=2&page=2");
    $page2->assertStatus(200);
    $data2 = $page2->json('data');
    expect(count($data2))->toBe(2);

    $ids1 = collect($data1)->pluck('id')->all();
    $ids2 = collect($data2)->pluck('id')->all();

    // Aucun chevauchement entre page 1 et page 2
    expect(array_intersect($ids1, $ids2))->toBeEmpty();
});

it('neutralise le badge si la vérification est caduque (verification_lapsed = true)', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post vérification caduque',
        'audience' => 'public',
    ]);

    $lapsedLiker = User::factory()->create([
        'name' => 'Expired Landlord',
        'badge' => 'landlord',
        'is_verified' => true,
        'verification_lapsed' => true,
        'status' => 'active',
    ]);

    PostLike::create(['post_id' => $post->id, 'user_id' => $lapsedLiker->id]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    $response = getJson("/api/square-cr1/posts/{$post->id}/likers");
    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data[0]['badge'])->toBe('none');
    expect($data[0]['verified'])->toBeFalse();
    expect($data[0]['ring_style'])->toBe('none');
});

it('retourne une liste vide lorsqu aucun like n existe', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post sans aucun like',
        'audience' => 'public',
        'likes_count' => 0,
    ]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    $response = getJson("/api/square-cr1/posts/{$post->id}/likers");

    $response->assertStatus(200)
        ->assertJson([
            'data' => [],
            'meta' => ['total' => 0],
        ]);
});

it('retourne 404 si la publication est inexistante', function () {
    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    $response = getJson('/api/square-cr1/posts/999999/likers');
    $response->assertStatus(404);
});

it('retourne 403 si la publication est restreinte et inaccessible au viewer', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post privé réservé aux followers',
        'audience' => 'followers',
    ]);

    $viewer = User::factory()->create();
    // Le viewer ne suit pas l'auteur
    Passport::actingAs($viewer);

    $response = getJson("/api/square-cr1/posts/{$post->id}/likers");
    $response->assertStatus(403);
});

it('exclut de la liste les utilisateurs bloqués par le viewer ou ayant bloqué le viewer', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post avec likers bloqués',
        'audience' => 'public',
    ]);

    $normalLiker = User::factory()->create(['status' => 'active']);
    $blockedLiker = User::factory()->create(['status' => 'active']);
    $blockerLiker = User::factory()->create(['status' => 'active']);

    PostLike::create(['post_id' => $post->id, 'user_id' => $normalLiker->id]);
    PostLike::create(['post_id' => $post->id, 'user_id' => $blockedLiker->id]);
    PostLike::create(['post_id' => $post->id, 'user_id' => $blockerLiker->id]);

    $viewer = User::factory()->create();

    // Le viewer bloque blockedLiker
    UserBlock::create(['blocker_id' => $viewer->id, 'blocked_id' => $blockedLiker->id]);

    // blockerLiker bloque le viewer
    UserBlock::create(['blocker_id' => $blockerLiker->id, 'blocked_id' => $viewer->id]);

    Passport::actingAs($viewer);

    $response = getJson("/api/square-cr1/posts/{$post->id}/likers");
    $response->assertStatus(200);

    $likerIds = collect($response->json('data'))->pluck('id')->all();

    expect($likerIds)->toContain($normalLiker->id);
    expect($likerIds)->not->toContain($blockedLiker->id);
    expect($likerIds)->not->toContain($blockerLiker->id);
});

it('exclut les utilisateurs suspendus ou supprimés', function () {
    $author = User::factory()->create();
    $post = Post::create([
        'user_id' => $author->id,
        'body' => 'Post avec comptes inactifs',
        'audience' => 'public',
    ]);

    $activeLiker = User::factory()->create(['status' => 'active']);
    $suspendedLiker = User::factory()->create(['status' => 'suspended']);
    $deletedLiker = User::factory()->create(['status' => 'active', 'deleted_at' => now()]);

    PostLike::create(['post_id' => $post->id, 'user_id' => $activeLiker->id]);
    PostLike::create(['post_id' => $post->id, 'user_id' => $suspendedLiker->id]);
    PostLike::create(['post_id' => $post->id, 'user_id' => $deletedLiker->id]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    $response = getJson("/api/square-cr1/posts/{$post->id}/likers");
    $response->assertStatus(200);

    $likerIds = collect($response->json('data'))->pluck('id')->all();
    expect($likerIds)->toEqual([$activeLiker->id]);
});
