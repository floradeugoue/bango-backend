<?php

use App\Models\Story\Story;
use App\Models\Story\StorySlide;
use App\Models\Story\StorySticker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('point 43: stories rail includes story_ids and list of stories for author', function () {
    Storage::fake('public');
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    // Create 2 active stories for author
    $story1 = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now()->subMinutes(10),
        'expires_at' => now()->addHours(23),
    ]);
    StorySlide::create([
        'story_id' => $story1->id,
        'media_type' => 'image',
        'media_path' => 'stories/test1.jpg',
        'media_url' => 'http://localhost/storage/stories/test1.jpg',
        'thumbnail_path' => 'stories/test1_thumb.jpg',
        'thumbnail_url' => 'http://localhost/storage/stories/test1_thumb.jpg',
        'order' => 0,
    ]);

    $story2 = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now()->subMinutes(5),
        'expires_at' => now()->addHours(23),
    ]);
    StorySlide::create([
        'story_id' => $story2->id,
        'media_type' => 'image',
        'media_path' => 'stories/test2.jpg',
        'media_url' => 'http://localhost/storage/stories/test2.jpg',
        'thumbnail_path' => 'stories/test2_thumb.jpg',
        'thumbnail_url' => 'http://localhost/storage/stories/test2_thumb.jpg',
        'order' => 0,
    ]);

    $res = $this->actingAs($viewer, 'api')->getJson('/api/stories/rail');
    $res->assertOk();
    $data = $res->json('data');

    expect($data)->not->toBeEmpty();
    $item = collect($data)->firstWhere('user.id', $author->id);
    expect($item)->not->toBeNull();
    expect($item['stories_count'])->toBe(2);
    expect($item['story_ids'])->toHaveCount(2);
    expect($item['stories'])->toHaveCount(2);
});

test('point 43: story slide retains background, drawings, framing and custom video thumbnail', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $videoFile = UploadedFile::fake()->create('clip.mp4', 1000, 'video/mp4');
    $thumbFile = UploadedFile::fake()->image('thumb.jpg', 200, 200);

    $res = $this->actingAs($user, 'api')->postJson('/api/stories', [
        'file' => $videoFile,
        'thumbnail' => $thumbFile,
        'background' => '#112233',
        'drawings' => [['x' => 10, 'y' => 20, 'color' => '#ffffff']],
        'framing' => ['crop_x' => 0, 'crop_y' => 0, 'scale' => 1.2],
    ]);

    $res->assertCreated();
    $storyId = $res->json('data.id');

    $story = Story::with('slides')->find($storyId);
    expect($story->slides)->toHaveCount(1);
    $slide = $story->slides->first();

    expect($slide->metadata['background'])->toBe('#112233');
    expect($slide->metadata['drawings'])->toBeArray();
    expect($slide->metadata['framing'])->toBeArray();
    expect($slide->thumbnail_url)->not->toBe($slide->media_url);

    // Test slide resource returns them
    $slideRes = $this->actingAs($user, 'api')->getJson("/api/stories/{$storyId}/slides");
    $slideRes->assertOk();
    $slideData = $slideRes->json('data.0');
    expect($slideData['background'])->toBe('#112233');
    expect($slideData['drawings'])->not->toBeNull();
    expect($slideData['framing'])->not->toBeNull();
});

test('point 43: stats are restricted to author only', function () {
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    $story = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);

    // Viewer request
    $resViewer = $this->actingAs($viewer, 'api')->getJson("/api/stories/{$story->id}");
    $resViewer->assertOk();
    expect($resViewer->json('data.stats'))->toBeNull();

    // Author request
    $resAuthor = $this->actingAs($author, 'api')->getJson("/api/stories/{$story->id}");
    $resAuthor->assertOk();
    expect($resAuthor->json('data.stats'))->toBeArray();
});

test('point 43: like, unlike, settings, stickers interactions and export', function () {
    Notification::fake();
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    $story = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now(),
        'expires_at' => now()->addHours(24),
    ]);
    $slide = StorySlide::create([
        'story_id' => $story->id,
        'media_type' => 'image',
        'media_path' => 'stories/img.jpg',
        'media_url' => 'http://localhost/storage/stories/img.jpg',
        'order' => 0,
    ]);

    // Like
    $likeRes = $this->actingAs($viewer, 'api')->postJson("/api/stories/{$story->id}/like");
    $likeRes->assertOk();
    expect($likeRes->json('data.is_liked'))->toBeTrue();
    expect($likeRes->json('data.likes_count'))->toBe(1);

    // Unlike
    $unlikeRes = $this->actingAs($viewer, 'api')->deleteJson("/api/stories/{$story->id}/like");
    $unlikeRes->assertOk();
    expect($unlikeRes->json('data.is_liked'))->toBeFalse();
    expect($unlikeRes->json('data.likes_count'))->toBe(0);

    // Settings
    $settingsRes = $this->actingAs($author, 'api')->getJson('/api/stories/settings');
    $settingsRes->assertOk();
    expect($settingsRes->json('data.allow_replies'))->toBe('everyone');

    $putSettingsRes = $this->actingAs($author, 'api')->putJson('/api/stories/settings', [
        'allow_replies' => 'followers',
        'save_to_gallery' => true,
    ]);
    $putSettingsRes->assertOk();
    expect($putSettingsRes->json('data.allow_replies'))->toBe('followers');
    expect($putSettingsRes->json('data.save_to_gallery'))->toBeTrue();

    // Sticker question answer
    $questionSticker = StorySticker::create([
        'story_slide_id' => $slide->id,
        'type' => 'question',
        'x' => 0.5,
        'y' => 0.5,
        'scale' => 1,
        'rotation' => 0,
        'data' => ['question' => 'Quelle est votre ville préférée ?'],
    ]);

    $answerRes = $this->actingAs($viewer, 'api')->postJson("/api/stories/stickers/{$questionSticker->id}/answer", [
        'answer' => 'Douala',
    ]);
    $answerRes->assertOk();

    // Export endpoint
    $exportRes = $this->actingAs($author, 'api')->getJson("/api/stories/{$story->id}/export");
    $exportRes->assertOk();
    expect($exportRes->json('data.status'))->toBe('ready');
});
