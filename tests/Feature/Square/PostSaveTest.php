<?php

use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('user can toggle save post on POST /square-cr1/posts/{id}/save and view in GET /square-cr1/posts/saved', function () {
    $user = User::factory()->create();
    $post = Post::create([
        'user_id' => $user->id,
        'body' => 'Une superbe publication à enregistrer',
        'audience' => 'public',
        'language' => 'fr',
    ]);

    Passport::actingAs($user);

    // 1. Initialement : non enregistré
    $showRes = $this->getJson("/api/posts/{$post->id}");
    $showRes->assertOk();
    expect($showRes->json('data.saved'))->toBeFalse()
        ->and($showRes->json('data.is_saved'))->toBeFalse();

    // 2. Première bascule : enregistrer le post
    $saveRes = $this->postJson("/api/square-cr1/posts/{$post->id}/save");
    $saveRes->assertOk();
    expect($saveRes->json('saved'))->toBeTrue()
        ->and($saveRes->json('is_saved'))->toBeTrue();

    // 3. PostResource reflète saved: true
    $showResAfterSave = $this->getJson("/api/posts/{$post->id}");
    $showResAfterSave->assertOk();
    expect($showResAfterSave->json('data.saved'))->toBeTrue()
        ->and($showResAfterSave->json('data.is_saved'))->toBeTrue();

    // 4. GET /square-cr1/posts/saved liste le post
    $savedListRes = $this->getJson('/api/square-cr1/posts/saved');
    $savedListRes->assertOk();
    expect($savedListRes->json('data'))->toHaveCount(1)
        ->and($savedListRes->json('data.0.id'))->toBe((string) $post->id)
        ->and($savedListRes->json('data.0.saved'))->toBeTrue();

    // 5. Deuxième bascule : désenregistrer le post
    $unsaveRes = $this->postJson("/api/square-cr1/posts/{$post->id}/save");
    $unsaveRes->assertOk();
    expect($unsaveRes->json('saved'))->toBeFalse()
        ->and($unsaveRes->json('is_saved'))->toBeFalse();

    // 6. La liste des enregistrements est désormais vide
    $savedListResAfter = $this->getJson('/api/square-cr1/posts/saved');
    $savedListResAfter->assertOk();
    expect($savedListResAfter->json('data'))->toBeEmpty();
});
