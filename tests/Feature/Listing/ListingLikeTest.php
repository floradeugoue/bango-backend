<?php

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
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

test('user can like and unlike a listing and check like status in resource and liked list', function (): void {
    $owner = User::factory()->create();
    $user = User::factory()->create();

    $listing = Listing::factory()->create([
        'owner_id' => $owner->id,
        'status' => ListingStatus::Published,
        'likes_count' => 0,
    ]);

    // Initial state via GET /listings/{id}
    Passport::actingAs($user);
    $response = $this->getJson("/api/listings/{$listing->id}");
    $response->assertOk()
        ->assertJsonPath('data.likes_count', 0)
        ->assertJsonPath('data.is_liked', false);

    // Like the listing: POST /listings/{id}/like
    $likeResponse = $this->postJson("/api/listings/{$listing->id}/like");
    $likeResponse->assertOk()
        ->assertJson([
            'success' => true,
            'liked' => true,
            'likes_count' => 1,
        ]);

    expect($listing->fresh()->likes_count)->toBe(1);

    // Liking again is idempotent
    $likeResponse2 = $this->postJson("/api/listings/{$listing->id}/like");
    $likeResponse2->assertOk()
        ->assertJsonPath('likes_count', 1);
    expect($listing->fresh()->likes_count)->toBe(1);

    // Resource now reflects is_liked = true
    $getRes = $this->getJson("/api/listings/{$listing->id}");
    $getRes->assertOk()
        ->assertJsonPath('data.likes_count', 1)
        ->assertJsonPath('data.is_liked', true);

    // Listing appears in GET /listings/liked
    $likedListRes = $this->getJson('/api/listings/liked');
    $likedListRes->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $listing->id)
        ->assertJsonPath('data.0.is_liked', true);

    // Unlike: DELETE /listings/{id}/like
    $unlikeResponse = $this->deleteJson("/api/listings/{$listing->id}/like");
    $unlikeResponse->assertOk()
        ->assertJson([
            'success' => true,
            'liked' => false,
            'likes_count' => 0,
        ]);

    expect($listing->fresh()->likes_count)->toBe(0);

    // Unlike again is idempotent
    $unlikeResponse2 = $this->deleteJson("/api/listings/{$listing->id}/like");
    $unlikeResponse2->assertOk()
        ->assertJsonPath('likes_count', 0);

    // Listing no longer appears in GET /listings/liked
    $likedListRes2 = $this->getJson('/api/listings/liked');
    $likedListRes2->assertOk()
        ->assertJsonCount(0, 'data');
});
