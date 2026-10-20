<?php

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

test('reels feed filters by author_id and resource exposes authorAvatar, author_city without Dakar fallback and uses display_name', function () {
    $author1 = User::factory()->create([
        'name' => 'Jean Dupont',
        'display_name' => 'Jean Le Créateur',
        'city' => null, // No city known
        'avatar_url' => 'https://storage.bango.cm/avatars/jean.jpg',
    ]);

    $author2 = User::factory()->create([
        'name' => 'Paul Martin',
        'display_name' => 'Paul Vidéos',
        'city' => 'Douala',
        'avatar_url' => 'https://storage.bango.cm/avatars/paul.jpg',
    ]);

    $reel1 = Reel::create([
        'user_id' => $author1->id,
        'title' => 'Reel Jean',
        'caption' => 'Vidéo de Jean',
        'video_url' => 'reels/video1.mp4',
        'status' => ReelStatus::Ready,
        'published_at' => now(),
    ]);

    $reel2 = Reel::create([
        'user_id' => $author2->id,
        'title' => 'Reel Paul',
        'caption' => 'Vidéo de Paul',
        'video_url' => 'reels/video2.mp4',
        'status' => ReelStatus::Ready,
        'published_at' => now(),
    ]);

    $viewer = User::factory()->create();
    Passport::actingAs($viewer);

    // 1. GET /api/reels/feed?author_id={id}
    $response = getJson("/api/reels/feed?author_id={$author1->id}");
    $response->assertOk();

    $reels = $response->json('data');
    expect($reels)->toHaveCount(1)
        ->and($reels[0]['id'])->toBe((string) $reel1->id);

    // 2. Check authorAvatar, author_city (null, no Dakar!), and display_name
    $item = $reels[0];
    expect($item['authorAvatar'])->toBe('https://storage.bango.cm/avatars/jean.jpg')
        ->and($item['author_city'])->toBeNull()
        ->and($item['authorCity'])->toBeNull()
        ->and($item['author_name'])->toBe('Jean Le Créateur')
        ->and($item['display_name'])->toBe('Jean Le Créateur')
        ->and($item['authorMeta'])->not->toContain('Dakar');

    // 3. Add comment and check display_name in comment resource
    $commentUser = User::factory()->create([
        'name' => 'Commentateur',
        'display_name' => 'Super Fan',
    ]);

    $comment = ReelComment::create([
        'reel_id' => $reel1->id,
        'user_id' => $commentUser->id,
        'body' => 'Génial ce reel !',
    ]);

    $resComments = getJson("/api/reels/{$reel1->id}/comments");
    $resComments->assertOk();
    $commentsData = $resComments->json('data');
    expect($commentsData[0]['author']['display_name'])->toBe('Super Fan')
        ->and($commentsData[0]['author']['name'])->toBe('Super Fan');
});
