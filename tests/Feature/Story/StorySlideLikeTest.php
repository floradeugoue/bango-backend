<?php

use App\Models\Story\Story;
use App\Models\Story\StoryLike;
use App\Models\Story\StorySlide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('user can like and unlike a specific story slide, and StorySlideResource exposes is_liked and likes_count', function () {
    Storage::fake('public');
    $author = User::factory()->create();
    $viewer1 = User::factory()->create();
    $viewer2 = User::factory()->create();

    $story = Story::factory()->create([
        'user_id' => $author->id,
        'published_at' => now()->subMinutes(10),
        'expires_at' => now()->addHours(23),
    ]);

    $slide1 = StorySlide::create([
        'story_id' => $story->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide1.jpg',
        'media_url' => 'http://localhost/storage/stories/slide1.jpg',
        'order' => 0,
    ]);

    $slide2 = StorySlide::create([
        'story_id' => $story->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide2.jpg',
        'media_url' => 'http://localhost/storage/stories/slide2.jpg',
        'order' => 1,
    ]);

    // Initially neither slide is liked
    $storyRes = $this->actingAs($viewer1, 'api')->getJson("/api/stories/{$story->id}");
    $storyRes->assertOk();
    expect($storyRes->json('data.is_liked'))->toBeFalse();
    expect($storyRes->json('data.likes_count'))->toBe(0);

    $slides = $storyRes->json('data.slides');
    expect($slides[0]['id'])->toBe($slide1->id);
    expect($slides[0]['is_liked'])->toBeFalse();
    expect($slides[0]['likes_count'])->toBe(0);
    expect($slides[1]['id'])->toBe($slide2->id);
    expect($slides[1]['is_liked'])->toBeFalse();
    expect($slides[1]['likes_count'])->toBe(0);

    // Viewer1 likes Slide 1
    $likeRes = $this->actingAs($viewer1, 'api')->postJson("/api/stories/{$story->id}/like", [
        'slide_id' => $slide1->id,
    ]);
    $likeRes->assertOk();
    expect($likeRes->json('data.is_liked'))->toBeTrue();
    expect($likeRes->json('data.likes_count'))->toBe(1);
    expect($likeRes->json('data.slide_id'))->toBe($slide1->id);
    expect($likeRes->json('data.slide_likes_count'))->toBe(1);

    // Verify DB entry
    expect(StoryLike::where('story_id', $story->id)->where('slide_id', $slide1->id)->where('user_id', $viewer1->id)->exists())->toBeTrue();

    // Viewer2 also likes Slide 1
    $like2Res = $this->actingAs($viewer2, 'api')->postJson("/api/stories/{$story->id}/like", [
        'slide_id' => $slide1->id,
    ]);
    $like2Res->assertOk();
    expect($like2Res->json('data.slide_likes_count'))->toBe(2);

    // Viewer1 likes Slide 2
    $likeSlide2Res = $this->actingAs($viewer1, 'api')->postJson("/api/stories/{$story->id}/like", [
        'slide_id' => $slide2->id,
    ]);
    $likeSlide2Res->assertOk();
    expect($likeSlide2Res->json('data.slide_likes_count'))->toBe(1);
    expect($likeSlide2Res->json('data.likes_count'))->toBe(3); // Total 2 on slide1 + 1 on slide2

    // Check StoryResource for Viewer1
    $storyCheck1 = $this->actingAs($viewer1, 'api')->getJson("/api/stories/{$story->id}");
    $storyCheck1->assertOk();
    expect($storyCheck1->json('data.is_liked'))->toBeTrue();
    expect($storyCheck1->json('data.likes_count'))->toBe(3);

    $slidesCheck1 = $storyCheck1->json('data.slides');
    expect($slidesCheck1[0]['is_liked'])->toBeTrue();
    expect($slidesCheck1[0]['likes_count'])->toBe(2);
    expect($slidesCheck1[1]['is_liked'])->toBeTrue();
    expect($slidesCheck1[1]['likes_count'])->toBe(1);

    // Viewer1 unlikes Slide 1
    $unlikeRes = $this->actingAs($viewer1, 'api')->deleteJson("/api/stories/{$story->id}/like", [
        'slide_id' => $slide1->id,
    ]);
    $unlikeRes->assertOk();
    // Viewer1 still has Slide 2 liked, so is_liked for story is still true!
    expect($unlikeRes->json('data.is_liked'))->toBeTrue();
    expect($unlikeRes->json('data.likes_count'))->toBe(2);
    expect($unlikeRes->json('data.slide_likes_count'))->toBe(1); // Viewer2 still likes slide 1

    // Check slide statuses for Viewer1
    $storyCheckAfterUnlike = $this->actingAs($viewer1, 'api')->getJson("/api/stories/{$story->id}");
    $slidesAfterUnlike = $storyCheckAfterUnlike->json('data.slides');
    expect($slidesAfterUnlike[0]['is_liked'])->toBeFalse();
    expect($slidesAfterUnlike[0]['likes_count'])->toBe(1); // Viewer2
    expect($slidesAfterUnlike[1]['is_liked'])->toBeTrue(); // Viewer1
    expect($slidesAfterUnlike[1]['likes_count'])->toBe(1);

    // Viewer1 unlikes Slide 2
    $unlikeSlide2Res = $this->actingAs($viewer1, 'api')->deleteJson("/api/stories/{$story->id}/like", [
        'slide_id' => $slide2->id,
    ]);
    $unlikeSlide2Res->assertOk();
    // Now Viewer1 likes no slides of this story
    expect($unlikeSlide2Res->json('data.is_liked'))->toBeFalse();
    expect($unlikeSlide2Res->json('data.likes_count'))->toBe(1); // only viewer2 on slide1
});

test('cannot like a slide with mismatched story_id', function () {
    $author = User::factory()->create();
    $viewer = User::factory()->create();

    $story1 = Story::factory()->create(['user_id' => $author->id]);
    $story2 = Story::factory()->create(['user_id' => $author->id]);

    $slide2 = StorySlide::create([
        'story_id' => $story2->id,
        'media_type' => 'image',
        'media_path' => 'stories/slide2.jpg',
        'media_url' => 'http://localhost/storage/stories/slide2.jpg',
        'order' => 0,
    ]);

    // Attempting to like slide2 through story1
    $res = $this->actingAs($viewer, 'api')->postJson("/api/stories/{$story1->id}/like", [
        'slide_id' => $slide2->id,
    ]);
    $res->assertNotFound();
});

