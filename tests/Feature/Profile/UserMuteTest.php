<?php

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\Profile\UserMute;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);
});

test('user can mute account for 30 days or indefinitely, view muted list, and unmute', function (): void {
    $me = User::factory()->create();
    $target = User::factory()->create([
        'name' => 'Jean Bailleur',
        'handle' => 'jean_bailleur',
    ]);

    Passport::actingAs($me);

    // 1. Mute target for 30 days via POST /users/{id}/mute
    $muteRes = $this->postJson("/api/users/{$target->id}/mute", [
        'days' => 30,
    ]);

    $muteRes->assertOk()
        ->assertJson([
            'success' => true,
            'muted' => true,
            'days' => 30,
        ]);

    expect($muteRes->json('expires_at'))->not->toBeNull();

    $muteRecord = UserMute::where('user_id', $me->id)->where('muted_user_id', $target->id)->first();
    expect($muteRecord)->not->toBeNull()
        ->and($muteRecord->expires_at)->not->toBeNull();

    // 2. Listing in GET /profile/muted
    $listRes = $this->getJson('/api/profile/muted');
    $listRes->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $target->id)
        ->assertJsonPath('data.0.name', 'Jean Bailleur');

    // 3. Unmute target via DELETE /users/{id}/mute
    $unmuteRes = $this->deleteJson("/api/users/{$target->id}/mute");
    $unmuteRes->assertOk()
        ->assertJson([
            'success' => true,
            'muted' => false,
        ]);

    expect(UserMute::where('user_id', $me->id)->where('muted_user_id', $target->id)->exists())->toBeFalse();

    // GET /profile/muted is now empty
    $listRes2 = $this->getJson('/api/profile/muted');
    $listRes2->assertOk()->assertJsonCount(0, 'data');

    // 4. Mute indefinitely (without days)
    $muteIndefiniteRes = $this->postJson("/api/users/{$target->id}/mute");
    $muteIndefiniteRes->assertOk()
        ->assertJson([
            'success' => true,
            'muted' => true,
            'expires_at' => null,
            'days' => null,
        ]);
});

test('feeds exclude muted users while delay is active and restore when expired or unmuted', function (): void {
    $viewer = User::factory()->create();
    $creator = User::factory()->create();

    // Create a Post (Square)
    $post = Post::create([
        'user_id' => $creator->id,
        'body' => 'Post de creator',
        'audience' => 'public',
        'is_archived' => false,
    ]);

    // Create a Reel
    $reel = Reel::create([
        'user_id' => $creator->id,
        'title' => 'Reel de creator',
        'video_url' => 'https://example.com/video.mp4',
        'status' => 'ready',
        'moderation_status' => 'approved',
        'allow_comments' => true,
        'allow_duet' => true,
        'allow_stitch' => true,
        'allow_download' => true,
    ]);

    // Create a Listing (Immo)
    $listing = Listing::factory()->create([
        'owner_id' => $creator->id,
        'status' => ListingStatus::Published,
    ]);

    Passport::actingAs($viewer);

    // Initial state: creator is visible in all 3 feeds
    $squareFeed = $this->getJson('/api/square-cr1/square/feed?tab=discover');
    $squareFeed->assertOk();
    $squareIds = collect($squareFeed->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($squareIds)->toContain((string) $post->id);

    $reelsFeed = $this->getJson('/api/reels/feed');
    $reelsFeed->assertOk();
    $reelIds = collect($reelsFeed->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($reelIds)->toContain((string) $reel->id);

    $listingsFeed = $this->getJson('/api/listings');
    $listingsFeed->assertOk();
    $listingIds = collect($listingsFeed->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($listingIds)->toContain((string) $listing->id);

    // Mute creator for 30 days
    $this->postJson("/api/users/{$creator->id}/mute", ['days' => 30])->assertOk();

    // Now creator content is hidden across Square, Reels, and Listings
    $squareFeedMuted = $this->getJson('/api/square-cr1/square/feed?tab=discover');
    $squareIdsMuted = collect($squareFeedMuted->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($squareIdsMuted)->not->toContain((string) $post->id);

    $reelsFeedMuted = $this->getJson('/api/reels/feed');
    $reelIdsMuted = collect($reelsFeedMuted->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($reelIdsMuted)->not->toContain((string) $reel->id);

    $listingsFeedMuted = $this->getJson('/api/listings');
    $listingIdsMuted = collect($listingsFeedMuted->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($listingIdsMuted)->not->toContain((string) $listing->id);

    // If mute is expired (e.g. set expires_at in the past)
    UserMute::where('user_id', $viewer->id)->where('muted_user_id', $creator->id)->update([
        'expires_at' => now()->subDay(),
    ]);

    // Creator content is visible again
    $squareFeedExpired = $this->getJson('/api/square-cr1/square/feed?tab=discover');
    $squareIdsExpired = collect($squareFeedExpired->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($squareIdsExpired)->toContain((string) $post->id);

    $listingsFeedExpired = $this->getJson('/api/listings');
    $listingIdsExpired = collect($listingsFeedExpired->json('data'))->pluck('id')->map(fn ($id) => (string) $id);
    expect($listingIdsExpired)->toContain((string) $listing->id);
});
