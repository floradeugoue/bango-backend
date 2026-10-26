<?php

use App\Enums\Reel\ReelReactionType;
use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->reel = Reel::factory()->create(['likes_count' => 0, 'dislikes_count' => 0, 'shares_count' => 0, 'views_count' => 0]);
    Passport::actingAs($this->user);
});

test('aimer un reel incremente likes_count et retirer son like le decremente', function () {
    // Like
    $response = $this->postJson("/api/reels/{$this->reel->id}/like");
    $response->assertStatus(200)
        ->assertJsonPath('data.user_reaction', 'like')
        ->assertJsonPath('data.likes_count', 1);

    expect($this->reel->fresh()->likes_count)->toBe(1);

    // Unlike
    $responseUnlike = $this->deleteJson("/api/reels/{$this->reel->id}/like");
    $responseUnlike->assertStatus(200)
        ->assertJsonPath('data.user_reaction', null)
        ->assertJsonPath('data.likes_count', 0);

    expect($this->reel->fresh()->likes_count)->toBe(0);
});

test('l exclusivite entre like et dislike est strictement respectee', function () {
    // 1. L'utilisateur like le reel
    $this->postJson("/api/reels/{$this->reel->id}/like");
    expect($this->reel->fresh()->likes_count)->toBe(1)
        ->and($this->reel->fresh()->dislikes_count)->toBe(0);

    // 2. L'utilisateur envoie un dislike : le like doit disparaitre au profit du dislike
    $responseDislike = $this->postJson("/api/reels/{$this->reel->id}/dislike");
    $responseDislike->assertStatus(200)
        ->assertJsonPath('data.user_reaction', 'dislike')
        ->assertJsonPath('data.likes_count', 0)
        ->assertJsonPath('data.dislikes_count', 1);

    expect($this->reel->fresh()->likes_count)->toBe(0)
        ->and($this->reel->fresh()->dislikes_count)->toBe(1);

    // 3. Vérifier en base : un seul enregistrement de réaction pour ce user et ce reel
    $reactions = $this->reel->reactions()->where('user_id', $this->user->id)->get();
    expect($reactions)->toHaveCount(1)
        ->and($reactions->first()->type)->toBe(ReelReactionType::Dislike);
});

test('enregistrer un partage incremente shares_count de maniere idempotente', function () {
    $mutationId = 'share-client-token-123';

    // Premier appel
    $response1 = $this->postJson("/api/reels/{$this->reel->id}/share", [
        'platform' => 'whatsapp',
        'client_mutation_id' => $mutationId,
    ]);
    $response1->assertStatus(200)
        ->assertJsonPath('data.shares_count', 1);

    // Rejeu réseau avec le même identifiant
    $response2 = $this->postJson("/api/reels/{$this->reel->id}/share", [
        'platform' => 'whatsapp',
        'client_mutation_id' => $mutationId,
    ]);
    $response2->assertStatus(200)
        ->assertJsonPath('data.shares_count', 1);

    expect($this->reel->fresh()->shares_count)->toBe(1);
});

test('l enregistrement des vues est protege contre les increments abusifs', function () {
    // Première vue : comptabilisée
    $response1 = $this->postJson("/api/reels/{$this->reel->id}/view");
    $response1->assertStatus(200)
        ->assertJsonPath('data.viewed', true)
        ->assertJsonPath('data.views_count', 1);

    expect($this->reel->fresh()->views_count)->toBe(1);

    // Deuxième lecture immédiate : non comptabilisée en doublon
    $response2 = $this->postJson("/api/reels/{$this->reel->id}/view");
    $response2->assertStatus(200)
        ->assertJsonPath('data.viewed', false)
        ->assertJsonPath('data.views_count', 1);

    expect($this->reel->fresh()->views_count)->toBe(1);
});

test('un utilisateur peut sauvegarder et retrouver un reel', function () {
    // Sauvegarder
    $response = $this->postJson("/api/reels/{$this->reel->id}/save");
    $response->assertStatus(200)
        ->assertJsonPath('data.saved', true);

    // Lister ses reels sauvegardés
    $listResponse = $this->getJson('/api/reels/user/saved');
    $listResponse->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $this->reel->id);

    // Retirer de ses sauvegardes
    $unsaveResponse = $this->postJson("/api/reels/{$this->reel->id}/save");
    $unsaveResponse->assertStatus(200)
        ->assertJsonPath('data.saved', false);
});

test('un utilisateur peut marquer un reel comme pas interesse', function () {
    $response = $this->postJson("/api/reels/{$this->reel->id}/not-interested");

    $response->assertStatus(200)
        ->assertJsonPath('data.not_interested', true);

    expect($this->user->notInterestedReels()->where('reel_id', $this->reel->id)->exists())->toBeTrue();
});

test('un utilisateur peut lister ses reels aimes les plus recents d abord', function () {
    $creator = User::factory()->create();

    $reel1 = Reel::factory()->create(['user_id' => $creator->id]);
    $reel2 = Reel::factory()->create(['user_id' => $creator->id]);

    // Aimer reel1
    $this->postJson("/api/reels/{$reel1->id}/like")->assertStatus(200);

    // Avancer le temps et aimer reel2
    $this->travel(10)->minutes();
    $this->postJson("/api/reels/{$reel2->id}/like")->assertStatus(200);

    // Lister ses reels aimés
    $response = $this->getJson('/api/reels/user/liked');
    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');

    $data = $response->json('data');
    // Le plus récent d'abord : reel2 puis reel1
    expect($data[0]['id'])->toBe((string) $reel2->id);
    expect($data[0]['liked'])->toBeTrue();
    expect($data[1]['id'])->toBe((string) $reel1->id);
    expect($data[1]['liked'])->toBeTrue();
});
