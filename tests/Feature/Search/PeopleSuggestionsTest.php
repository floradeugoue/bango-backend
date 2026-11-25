<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
});

it('requires authentication for people suggestions', function () {
    $response = $this->getJson('/people/suggestions');
    $response->assertStatus(401);

    $apiResponse = $this->getJson('/api/people/suggestions');
    $apiResponse->assertStatus(401);
});

it('returns suggestions for neighbours and city residents with expected fields', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);
    Passport::actingAs($me);

    $neighbour = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
        'display_name' => 'Voisin Akwa',
        'handle' => 'voisin_akwa',
        'badge' => 'landlord',
        'is_verified' => true,
    ]);

    $cityResident = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Bonapriso',
        'display_name' => 'Habitant Bonapriso',
        'handle' => 'habitant_bonapriso',
        'badge' => 'none',
        'is_verified' => false,
    ]);

    $response = $this->getJson('/people/suggestions?scope=city');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data)->toHaveCount(2);

    // Même quartier d'abord, puis même ville
    expect($data[0]['id'])->toBe((string) $neighbour->id);
    expect($data[0]['name'])->toBe('Voisin Akwa');
    expect($data[0]['handle'])->toBe('voisin_akwa');
    expect($data[0]['badge'])->toBe('landlord');
    expect($data[0]['is_verified'])->toBeTrue();
    expect($data[0]['city'])->toBe('Douala');
    expect($data[0]['neighbourhood'])->toBe('Akwa');
    expect($data[0]['mutual_followers_count'])->toBe(0);

    expect($data[1]['id'])->toBe((string) $cityResident->id);
    expect($data[1]['city'])->toBe('Douala');
    expect($data[1]['neighbourhood'])->toBe('Bonapriso');
});

it('excludes self, followed users, blocked users, and accounts who blocked me', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);
    Passport::actingAs($me);

    $alreadyFollowed = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);
    DB::table('follows')->insert([
        'follower_id' => $me->id,
        'following_id' => $alreadyFollowed->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $blockedByMe = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);
    DB::table('user_blocks')->insert([
        'blocker_id' => $me->id,
        'blocked_id' => $blockedByMe->id,
        'created_at' => now(),
    ]);

    $blockingMe = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);
    DB::table('user_blocks')->insert([
        'blocker_id' => $blockingMe->id,
        'blocked_id' => $me->id,
        'created_at' => now(),
    ]);

    $inactiveUser = User::factory()->create([
        'status' => 'suspended',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);

    $validNeighbour = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);

    $response = $this->getJson('/people/suggestions?scope=neighbourhood');
    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $validNeighbour->id);
    expect($ids)->not->toContain((string) $me->id);
    expect($ids)->not->toContain((string) $alreadyFollowed->id);
    expect($ids)->not->toContain((string) $blockedByMe->id);
    expect($ids)->not->toContain((string) $blockingMe->id);
    expect($ids)->not->toContain((string) $inactiveUser->id);
});

it('filters strictly by neighbourhood when scope=neighbourhood', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);
    Passport::actingAs($me);

    $sameNeighbourhood = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);

    $otherNeighbourhood = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $response = $this->getJson('/people/suggestions?scope=neighbourhood');
    $response->assertStatus(200);

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toContain((string) $sameNeighbourhood->id);
    expect($ids)->not->toContain((string) $otherNeighbourhood->id);
});

it('computes mutual_followers_count accurately', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
    ]);
    Passport::actingAs($me);

    $neighbour = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Bonanjo',
    ]);

    // Deux suiveurs mutuels (suivent $me ET suivent $neighbour)
    $mutualFollower1 = User::factory()->create(['status' => 'active']);
    $mutualFollower2 = User::factory()->create(['status' => 'active']);
    // Un suiveur qui suit seulement $neighbour (pas $me)
    $otherFollower = User::factory()->create(['status' => 'active']);

    DB::table('follows')->insert([
        // follower 1
        ['follower_id' => $mutualFollower1->id, 'following_id' => $me->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $mutualFollower1->id, 'following_id' => $neighbour->id, 'created_at' => now(), 'updated_at' => now()],
        // follower 2
        ['follower_id' => $mutualFollower2->id, 'following_id' => $me->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $mutualFollower2->id, 'following_id' => $neighbour->id, 'created_at' => now(), 'updated_at' => now()],
        // other follower
        ['follower_id' => $otherFollower->id, 'following_id' => $neighbour->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson('/people/suggestions?scope=neighbourhood');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data[0]['id'])->toBe((string) $neighbour->id);
    expect($data[0]['mutual_followers_count'])->toBe(2);
});

it('allows filtering search/people by city and neighbourhood', function () {
    $me = User::factory()->create(['status' => 'active']);
    Passport::actingAs($me);

    $userDakarAlmadies = User::factory()->create([
        'status' => 'active',
        'handle' => 'habitant_almadies',
        'display_name' => 'Fatou Almadies',
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
    ]);

    $userDakarOuakam = User::factory()->create([
        'status' => 'active',
        'handle' => 'habitant_ouakam',
        'display_name' => 'Cheikh Ouakam',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);

    $userDoualaAkwa = User::factory()->create([
        'status' => 'active',
        'handle' => 'habitant_douala',
        'display_name' => 'Paul Douala',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);

    // Filtrer par ville Dakar
    $resCity = $this->getJson('/search/people?city=Dakar');
    $resCity->assertStatus(200);
    $handlesCity = collect($resCity->json('data'))->pluck('handle')->all();
    expect($handlesCity)->toContain('habitant_almadies');
    expect($handlesCity)->toContain('habitant_ouakam');
    expect($handlesCity)->not->toContain('habitant_douala');

    // Filtrer par quartier Almadies
    $resNeighbourhood = $this->getJson('/search/people?neighbourhood=Almadies');
    $resNeighbourhood->assertStatus(200);
    $handlesNeighbourhood = collect($resNeighbourhood->json('data'))->pluck('handle')->all();
    expect($handlesNeighbourhood)->toContain('habitant_almadies');
    expect($handlesNeighbourhood)->not->toContain('habitant_ouakam');
    expect($handlesNeighbourhood)->not->toContain('habitant_douala');
});

it('suggests friends of friends regardless of city and ranks by mutual links count', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Douala',
        'neighbourhood' => 'Akwa',
    ]);
    Passport::actingAs($me);

    $friendAwa = User::factory()->create([
        'status' => 'active',
        'name' => 'Awa Diop',
        'display_name' => 'Awa Diop',
        'handle' => 'awa_diop',
    ]);

    $friendOumar = User::factory()->create([
        'status' => 'active',
        'name' => 'Oumar Sy',
        'display_name' => 'Oumar Sy',
        'handle' => 'oumar_sy',
    ]);

    // Je suis Awa et Oumar
    DB::table('follows')->insert([
        ['follower_id' => $me->id, 'following_id' => $friendAwa->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $me->id, 'following_id' => $friendOumar->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Samba habite à Abidjan (autre ville) et est suivi par Awa ET Oumar (2 liens en commun)
    $samba = User::factory()->create([
        'status' => 'active',
        'name' => 'Samba Diallo',
        'display_name' => 'Samba Diallo',
        'handle' => 'samba_diallo',
        'city' => 'Abidjan',
        'neighbourhood' => 'Cocody',
    ]);

    // Fatou habite à Paris (autre ville) et est suivie seulement par Awa (1 lien en commun)
    $fatou = User::factory()->create([
        'status' => 'active',
        'name' => 'Fatou Ndiaye',
        'display_name' => 'Fatou Ndiaye',
        'handle' => 'fatou_ndiaye',
        'city' => 'Paris',
        'neighbourhood' => '16e',
    ]);

    DB::table('follows')->insert([
        ['follower_id' => $friendAwa->id, 'following_id' => $samba->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $friendOumar->id, 'following_id' => $samba->id, 'created_at' => now(), 'updated_at' => now()],
        ['follower_id' => $friendAwa->id, 'following_id' => $fatou->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->getJson('/people/suggestions');
    $response->assertStatus(200);

    $data = $response->json('data');

    // Samba (2 liens) doit apparaître en premier, puis Fatou (1 lien)
    expect($data[0]['id'])->toBe((string) $samba->id);
    expect($data[0]['reason'])->toBe('friends_of_friends');
    expect($data[0]['mutual_followers_count'])->toBe(2);
    expect($data[0]['mutual_names'])->toContain('Awa');
    expect($data[0]['mutual_names'])->toContain('Oumar');

    expect($data[1]['id'])->toBe((string) $fatou->id);
    expect($data[1]['reason'])->toBe('friends_of_friends');
    expect($data[1]['mutual_followers_count'])->toBe(1);
    expect($data[1]['mutual_names'])->toBe(['Awa']);
});

it('mixes suggestions in strict order: friends of friends, contacts, neighbourhood, city', function () {
    $me = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
    ]);
    Passport::actingAs($me);

    // 1. Ami d'ami (Abidjan)
    $myFollowing = User::factory()->create(['status' => 'active']);
    DB::table('follows')->insert([
        'follower_id' => $me->id,
        'following_id' => $myFollowing->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $friendOfFriend = User::factory()->create([
        'status' => 'active',
        'city' => 'Abidjan',
        'handle' => 'ami_d_ami',
    ]);
    DB::table('follows')->insert([
        'follower_id' => $myFollowing->id,
        'following_id' => $friendOfFriend->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Contact du carnet d'adresses (Yaoundé) préalablement rapproché
    $contactUser = User::factory()->create([
        'status' => 'active',
        'phone' => '+237690112233',
        'city' => 'Yaoundé',
        'handle' => 'mon_contact',
    ]);
    $digits = substr(preg_replace('/\D/', '', '+237690112233'), -8);
    $sha256 = hash('sha256', $digits);

    // Envoi des empreintes du téléphone
    $this->postJson('/api/friends/match-fingerprints', [
        'fingerprints' => [$sha256],
    ])->assertOk();

    // 3. Voisin du quartier (Dakar, Ouakam)
    $neighbour = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Ouakam',
        'handle' => 'mon_voisin',
    ]);

    // 4. Habitant de la ville (Dakar, Almadies)
    $cityResident = User::factory()->create([
        'status' => 'active',
        'city' => 'Dakar',
        'neighbourhood' => 'Almadies',
        'handle' => 'mon_concitoyen',
    ]);

    $response = $this->getJson('/people/suggestions');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect(count($data))->toBeGreaterThanOrEqual(4);

    // Vérification de l'ordre strict
    expect($data[0]['id'])->toBe((string) $friendOfFriend->id);
    expect($data[0]['reason'])->toBe('friends_of_friends');

    expect($data[1]['id'])->toBe((string) $contactUser->id);
    expect($data[1]['reason'])->toBe('contact');

    expect($data[2]['id'])->toBe((string) $neighbour->id);
    expect($data[2]['reason'])->toBe('neighbourhood');

    expect($data[3]['id'])->toBe((string) $cityResident->id);
    expect($data[3]['reason'])->toBe('city');
});

it('limits mutual_names to at most two first names even with multiple connections', function () {
    $me = User::factory()->create(['status' => 'active']);
    Passport::actingAs($me);

    $friends = collect([
        User::factory()->create(['name' => 'Awa Ndiaye', 'display_name' => 'Awa Ndiaye']),
        User::factory()->create(['name' => 'Mamadou Ba', 'display_name' => 'Mamadou Ba']),
        User::factory()->create(['name' => 'Cheikh Diop', 'display_name' => 'Cheikh Diop']),
    ]);

    foreach ($friends as $f) {
        DB::table('follows')->insert([
            'follower_id' => $me->id,
            'following_id' => $f->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $target = User::factory()->create(['status' => 'active', 'city' => 'Lomé']);
    foreach ($friends as $f) {
        DB::table('follows')->insert([
            'follower_id' => $f->id,
            'following_id' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $response = $this->getJson('/people/suggestions');
    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data[0]['id'])->toBe((string) $target->id);
    expect($data[0]['mutual_followers_count'])->toBe(3);
    expect($data[0]['mutual_names'])->toHaveCount(2);
});
