<?php

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    Passport::actingAs($this->user);
});

test('le feed vertical ne retourne que les reels dont le statut est ready', function () {
    Reel::factory()->create(['status' => ReelStatus::Ready, 'caption' => 'Reel visible']);
    Reel::factory()->draft()->create(['caption' => 'Reel brouillon']);
    Reel::factory()->processing()->create(['caption' => 'Reel en cours']);

    $response = $this->getJson('/api/reels/feed');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.caption', 'Reel visible');
});

test('le feed exclut les reels marques pas interesse', function () {
    Passport::actingAs($this->user);

    $reel1 = Reel::factory()->create(['status' => ReelStatus::Ready, 'caption' => 'Reel intéressant']);
    $reel2 = Reel::factory()->create(['status' => ReelStatus::Ready, 'caption' => 'Reel indésirable']);

    $this->user->notInterestedReels()->attach($reel2->id);

    $response = $this->getJson('/api/reels/feed');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $reel1->id);
});

test('le feed peut filtrer par sujet/topic', function () {
    Reel::factory()->create(['status' => ReelStatus::Ready, 'topic' => 'food']);
    Reel::factory()->create(['status' => ReelStatus::Ready, 'topic' => 'fashion']);

    $response = $this->getJson('/api/reels/feed?topic=food');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.topic', 'food');
});

test('un utilisateur peut consulter le detail d un reel', function () {
    $reel = Reel::factory()->create(['status' => ReelStatus::Ready, 'caption' => 'Superbe coucher de soleil']);

    $response = $this->getJson("/api/reels/{$reel->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', (string) $reel->id)
        ->assertJsonPath('data.caption', 'Superbe coucher de soleil');
});

test('un utilisateur peut supprimer son propre reel mais pas celui d autrui', function () {
    Passport::actingAs($this->user);

    $myReel = Reel::factory()->create(['user_id' => $this->user->id]);
    $otherUser = User::factory()->create();
    $otherReel = Reel::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->deleteJson("/api/reels/{$myReel->id}");
    $response->assertStatus(200);
    $this->assertSoftDeleted('reels', ['id' => $myReel->id]);

    $failResponse = $this->deleteJson("/api/reels/{$otherReel->id}");
    $failResponse->assertStatus(422);
});
