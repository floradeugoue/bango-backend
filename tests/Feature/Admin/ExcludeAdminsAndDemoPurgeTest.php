<?php

use App\Models\Auth\Role;
use App\Models\Square\Post;
use App\Models\User;
use App\Services\Support\DemoAccountCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

test('admin accounts are excluded from people search', function () {
    $me = User::factory()->create(['status' => 'active']);
    Passport::actingAs($me);

    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'description' => 'Admin']);
    $directionRole = Role::firstOrCreate(['slug' => 'direction'], ['name' => 'Direction', 'description' => 'Direction']);

    // Compte Direction BANGO (@bango_admin, 1)
    $bangoAdmin = User::factory()->create([
        'name' => 'Direction BANGO',
        'display_name' => 'Direction BANGO',
        'handle' => 'bango_admin',
        'email' => 'admin@bangoapp.com',
        'role_id' => $directionRole->id,
        'status' => 'active',
    ]);

    // Autre compte avec rôle admin
    $otherAdmin = User::factory()->create([
        'name' => 'Super Administrateur',
        'display_name' => 'Super Admin',
        'handle' => 'super_admin_ops',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    // Vrai utilisateur classique
    $regularUser = User::factory()->create([
        'name' => 'Floriane Deugoue',
        'display_name' => 'Floriane Deugoue',
        'handle' => 'floriane_user',
        'status' => 'active',
    ]);

    // Compte de recette (recette.prod.*) qui doit rester visible
    $recetteUser = User::factory()->create([
        'name' => 'Recette Prod Mobile',
        'display_name' => 'Recette Prod',
        'handle' => 'recette_prod_tester',
        'email' => 'recette.prod.mobile@bango.app',
        'status' => 'active',
    ]);

    $response = $this->getJson('/search/people');
    $response->assertOk();

    $handles = collect($response->json('data'))->pluck('handle')->all();

    expect($handles)->toContain('floriane_user');
    expect($handles)->toContain('recette_prod_tester');
    expect($handles)->not->toContain('bango_admin');
    expect($handles)->not->toContain('super_admin_ops');
});

test('admin accounts are excluded from people suggestions', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);
    Passport::actingAs($me);

    $directionRole = Role::firstOrCreate(['slug' => 'direction'], ['name' => 'Direction', 'description' => 'Direction']);

    $bangoAdmin = User::factory()->create([
        'name' => 'Direction BANGO',
        'handle' => 'bango_admin',
        'role_id' => $directionRole->id,
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'status' => 'active',
    ]);

    $neighbour = User::factory()->create([
        'name' => 'Voisin Akwa',
        'handle' => 'voisin_akwa',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'status' => 'active',
    ]);

    $response = $this->getJson('/people/suggestions');
    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $neighbour->id);
    expect($ids)->not->toContain((string) $bangoAdmin->id);
});

test('admin accounts are excluded from followers and following lists', function () {
    $me = User::factory()->create(['status' => 'active']);
    Passport::actingAs($me);

    $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'description' => 'Admin']);

    $bangoAdmin = User::factory()->create([
        'name' => 'Direction BANGO',
        'handle' => 'bango_admin',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    $regularFriend = User::factory()->create([
        'name' => 'Ami Standard',
        'handle' => 'ami_standard',
        'status' => 'active',
    ]);

    // Les deux suivent $me
    DB::table('follows')->insert([
        ['follower_id' => $bangoAdmin->id, 'following_id' => $me->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $regularFriend->id, 'following_id' => $me->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // $me suit les deux
    DB::table('follows')->insert([
        ['follower_id' => $me->id, 'following_id' => $bangoAdmin->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $me->id, 'following_id' => $regularFriend->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // 1. Liste des abonnés de $me
    $resFollowers = $this->getJson('/api/profile/followers');
    $resFollowers->assertOk();
    $followerHandles = collect($resFollowers->json('data'))->pluck('handle')->all();
    expect($followerHandles)->toContain('ami_standard');
    expect($followerHandles)->not->toContain('bango_admin');

    // 2. Liste des abonnements de $me
    $resFollowing = $this->getJson('/api/profile/following');
    $resFollowing->assertOk();
    $followingHandles = collect($resFollowing->json('data'))->pluck('handle')->all();
    expect($followingHandles)->toContain('ami_standard');
    expect($followingHandles)->not->toContain('bango_admin');
});

test('cleanup service purges demo accounts and contents without touching recette accounts', function () {
    // 1. Faux comptes de démo
    $samuel = User::factory()->create([
        'id' => 7,
        'name' => 'Samuel Moukoko',
        'handle' => 'samuel_moukoko',
        'email' => 'samuel.moukoko@bangoapp.com',
    ]);

    $postSamuel = Post::factory()->create([
        'user_id' => $samuel->id,
        'body' => 'Post de démo Samuel',
    ]);

    // 2. Compte de recette qui DOIT être préservé
    $recette = User::factory()->create([
        'name' => 'Compte Recette Test',
        'handle' => 'recette_prod_1',
        'email' => 'recette.prod.samuel@bango.app',
    ]);

    $postRecette = Post::factory()->create([
        'user_id' => $recette->id,
        'body' => 'Post de recette valide',
    ]);

    $service = app(DemoAccountCleanupService::class);
    $result = $service->purge();

    expect($result['users_deleted'])->toBeGreaterThanOrEqual(1);

    // Samuel et son post ont disparu
    expect(User::find($samuel->id))->toBeNull();
    expect(Post::find($postSamuel->id))->toBeNull();

    // Le compte de recette et son post sont intacts
    expect(User::find($recette->id))->not->toBeNull();
    expect(Post::find($postRecette->id))->not->toBeNull();
});

test('artisan demo purge command executes smoothly', function () {
    User::factory()->create([
        'handle' => 'stephane_deco',
        'email' => 'stephane.deco@bangoapp.com',
        'name' => 'Stéphane Nguemo',
    ]);

    $this->artisan('demo:purge', ['--force' => true])
        ->expectsOutputToContain('Purge terminée avec succès')
        ->assertSuccessful();

    expect(User::where('handle', 'stephane_deco')->exists())->toBeFalse();
});
