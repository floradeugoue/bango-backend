<?php

use App\Models\Profile\UserBlock;
use App\Models\Reel\Sound;
use App\Models\Square\Hashtag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('permet de rechercher des hashtags avec normalisation et gestion du prefixe #', function () {
    Hashtag::create(['name' => 'BangoCameroun', 'normalized_name' => 'bangocameroun', 'posts_count' => 120]);
    Hashtag::create(['name' => 'BangoImmo', 'normalized_name' => 'bangoimmo', 'posts_count' => 50]);
    Hashtag::create(['name' => 'DoualaVibes', 'normalized_name' => 'doualavibes', 'posts_count' => 80]);

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    // Recherche avec préfixe # et majuscules
    $encoded = urlencode('#BANGO');
    $res1 = getJson("/api/search/hashtags?q={$encoded}");
    $res1->assertStatus(200);

    $tags = $res1->json('data');
    expect(count($tags))->toBe(2);

    // Ordre décroissant par posts_count
    expect($tags[0]['name'])->toBe('BangoCameroun');
    expect($tags[1]['name'])->toBe('BangoImmo');

    // Recherche avec espace ou terme partiel
    $encodedSpace = urlencode('  immo  ');
    $res2 = getJson("/api/search/hashtags?q={$encodedSpace}");
    $res2->assertStatus(200);
    expect(count($res2->json('data')))->toBe(1);
    expect($res2->json('data.0.name'))->toBe('BangoImmo');

});

it('prend en charge la pagination des hashtags', function () {
    for ($i = 1; $i <= 5; $i++) {
        Hashtag::create([
            'name' => "Tag{$i}",
            'normalized_name' => "tag{$i}",
            'posts_count' => $i * 10,
        ]);
    }

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/search/hashtags?per_page=2&page=1');
    $response->assertStatus(200);

    expect(count($response->json('data')))->toBe(2);
    expect($response->json('meta.total'))->toBe(5);
    expect($response->json('meta.current_page'))->toBe(1);
});

it('retourne les trois categories reelles pour l endpoint unifie des tendances', function () {
    // 1. Hashtags réels
    Hashtag::create(['name' => 'YaoundeCity', 'normalized_name' => 'yaoundecity', 'posts_count' => 300]);
    Hashtag::create(['name' => 'BafoussamVibes', 'normalized_name' => 'bafoussamvibes', 'posts_count' => 150]);
    Hashtag::create(['name' => 'ZeroPost', 'normalized_name' => 'zeropost', 'posts_count' => 0]); // ne doit pas apparaître

    // 2. Musiques réelles RL
    $sound1 = Sound::create([
        'id' => 'makossa-classic-1',
        'title' => 'Makossa Beat 1',
        'artist' => 'Artist A',
        'audio_url' => 'sounds/makossa1.mp3',
        'duration_seconds' => 30,
        'provenance' => 'library',
        'license_type' => 'commercial',
        'license_name' => 'Standard',
        'rights_status' => 'verified',
        'is_published' => true,
        'is_removed' => false,
        'uses_count' => 100,
        'base_uses' => 20,
    ]);

    $sound2 = Sound::create([
        'id' => 'afrobeats-hit-2',
        'title' => 'Afrobeats Summer',
        'artist' => 'Artist B',
        'audio_url' => 'sounds/afro2.mp3',
        'duration_seconds' => 45,
        'provenance' => 'library',
        'license_type' => 'commercial',
        'license_name' => 'Standard',
        'rights_status' => 'verified',
        'is_published' => true,
        'is_removed' => false,
        'uses_count' => 500,
        'base_uses' => 50,
    ]);

    // Son non publié (ne doit pas être inclus)
    Sound::create([
        'id' => 'draft-sound-3',
        'title' => 'Draft Sound',
        'artist' => 'Artist C',
        'audio_url' => 'sounds/draft.mp3',
        'duration_seconds' => 15,
        'provenance' => 'library',
        'license_type' => 'commercial',
        'license_name' => 'Standard',
        'rights_status' => 'pending',
        'is_published' => false,
        'is_removed' => false,
        'uses_count' => 999,
    ]);

    // 3. Créateurs émergents réels
    $creator1 = User::factory()->create([
        'name' => 'Manu Creator',
        'handle' => 'manu_creator',
        'account_type' => 'pro',
        'status' => 'active',
        'is_private' => false,
    ]);

    $creator2 = User::factory()->create([
        'name' => 'Sarah Verified Agent',
        'handle' => 'sarah_agent',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
        'status' => 'active',
        'is_private' => false,
    ]);

    // Utilisateur privé (ne doit pas apparaître)
    User::factory()->create([
        'name' => 'Private Creator',
        'handle' => 'private_creator',
        'account_type' => 'pro',
        'status' => 'active',
        'is_private' => true,
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/trends');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'top_hashtags',
                'trending_sounds',
                'emerging_creators',
            ],
        ]);

    $hashtags = $response->json('data.top_hashtags');
    expect(count($hashtags))->toBe(2);
    expect($hashtags[0]['name'])->toBe('YaoundeCity');
    expect($hashtags[1]['name'])->toBe('BafoussamVibes');

    $sounds = $response->json('data.trending_sounds');
    expect(count($sounds))->toBe(2);
    // Le son le plus utilisé est le premier
    expect($sounds[0]['id'])->toBe('afrobeats-hit-2');
    expect($sounds[1]['id'])->toBe('makossa-classic-1');

    $creators = $response->json('data.emerging_creators');
    expect(count($creators))->toBe(2);
    $creatorHandles = collect($creators)->pluck('handle')->all();
    expect($creatorHandles)->toContain('manu_creator');
    expect($creatorHandles)->toContain('sarah_agent');
    expect($creatorHandles)->not->toContain('private_creator');
});

it('retourne des listes vides stables lorsqu aucune donnee n est presente dans les tendances', function () {
    $viewer = User::factory()->create(['status' => 'active']);
    Passport::actingAs($viewer);

    $response = getJson('/api/trends');

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'top_hashtags' => [],
                'trending_sounds' => [],
                'emerging_creators' => [],
            ],
        ]);
});

it('exclut des createurs emergents les utilisateurs bloques par le viewer', function () {
    $creator = User::factory()->create([
        'name' => 'Blocked Creator',
        'handle' => 'blocked_creator',
        'account_type' => 'pro',
        'status' => 'active',
        'is_private' => false,
    ]);

    $viewer = User::factory()->create(['status' => 'active']);
    UserBlock::create(['blocker_id' => $viewer->id, 'blocked_id' => $creator->id]);

    Passport::actingAs($viewer);

    $response = getJson('/api/trends');
    $response->assertStatus(200);

    $creators = $response->json('data.emerging_creators');
    expect($creators)->toBeEmpty();
});
